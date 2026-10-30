<?php

namespace App\Services;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Mail\ConfirmacionDeBajaDeCuenta;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SolicitudDeBaja;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * **La baja de una cuenta** (2026-10-30): pedirla, confirmarla, cancelarla y ejecutarla.
 *
 * La página pública `/eliminar-cuenta` es el requisito de Google Play; este servicio es lo que hay detrás.
 *
 * ## El plazo y la cancelación
 *
 * La baja **no es inmediata**: al confirmar desde el correo queda *programada* para **30 días** después
 * (`config('legal.dias_de_gracia_de_la_baja')`). Se cancela de dos maneras, y las dos se registran:
 *
 *  - **iniciando sesión** en cualquier superficie (web o app): el propio usuario sigue usando su cuenta, así
 *    que el pedido caduca (`cancelarPorInicioDeSesion()`);
 *  - con el **enlace de cancelación** del correo, para quien no puede o no quiere entrar.
 *
 * ## Qué se borra y qué no (decisión del usuario, 2026-10-30)
 *
 * La página declara que **se borra todo lo generado por la cuenta**, incluidas historias clínicas y
 * consultas. La ejecución, en cambio, es deliberadamente conservadora con lo clínico: **se borran la
 * cuenta, la ficha de médico, sus secretarías, sus vínculos y sus archivos**, y las filas clínicas del
 * `reg_medico` **no se tocan** (quedan sin dueño). El motivo es no corromper datos clínicos que ya no se
 * pueden reconstruir; el desajuste entre lo que dice la página y lo que hace el sistema está anotado como
 * riesgo y es una decisión pendiente del dueño del producto, no un olvido de este servicio.
 *
 * ⚠️ `ejecutar()` **no se corre sola en los tests ni en desarrollo**: el comando
 * `cuentas:ejecutar-bajas` simula por defecto y sólo borra con `--ejecutar`.
 */
final class BajasDeCuenta
{
    /** Sólo los médicos y las secretarías tienen cuenta en la app (los pacientes del portal no). */
    public const ROLES = ['Medico', 'Secretaria'];

    /**
     * **Pedir la baja.** Devuelve la solicitud, o `null` si el correo no es de una cuenta que pueda pedirla.
     *
     * El `null` no se le muestra al visitante: el formulario contesta lo mismo exista o no la cuenta, para
     * que la página no sirva para averiguar qué correos están registrados.
     *
     * Un pedido nuevo **cancela los anteriores** que estuvieran pendientes o programados: el enlace que
     * vale es el último que llegó al correo.
     */
    public function solicitar(string $email): ?SolicitudDeBaja
    {
        $email = mb_strtolower(trim($email));
        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null || ! $user->hasAnyRole(self::ROLES)) {
            return null;
        }

        SolicitudDeBaja::where('user_id', $user->id)
            ->whereIn('estado', [SolicitudDeBaja::PENDIENTE, SolicitudDeBaja::PROGRAMADA])
            ->update([
                'estado'      => SolicitudDeBaja::CANCELADA,
                'cancelada_en' => now(),
                'motivo'      => 'pedido-nuevo',
            ]);

        $solicitud = SolicitudDeBaja::create([
            'user_id' => $user->id,
            'email'   => $user->email,
            'token'   => Str::random(64),
            'estado'  => SolicitudDeBaja::PENDIENTE,
        ]);

        // Si el correo falla, el pedido sigue en pie: se registra y el usuario puede volver a pedirlo.
        try {
            Mail::to($solicitud->email)->send(new ConfirmacionDeBajaDeCuenta($solicitud));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo de confirmación de baja.', [
                'solicitud' => $solicitud->id,
                'error'     => $e->getMessage(),
            ]);
        }

        return $solicitud;
    }

    /** **Confirmar la baja** desde el enlace del correo: arranca el plazo de 30 días. */
    public function confirmar(string $token): ?SolicitudDeBaja
    {
        $solicitud = SolicitudDeBaja::where('token', $token)->first();

        if ($solicitud === null || ! $solicitud->estaPendiente()) {
            return null;
        }

        $solicitud->update([
            'estado'              => SolicitudDeBaja::PROGRAMADA,
            'confirmada_en'       => now(),
            'ejecutar_despues_de' => now()->addDays((int) config('legal.dias_de_gracia_de_la_baja', 30)),
        ]);

        return $solicitud->refresh();
    }

    public function cancelar(SolicitudDeBaja $solicitud, string $motivo): void
    {
        if (! in_array($solicitud->estado, [SolicitudDeBaja::PENDIENTE, SolicitudDeBaja::PROGRAMADA], true)) {
            return;
        }

        $solicitud->update([
            'estado'      => SolicitudDeBaja::CANCELADA,
            'cancelada_en' => now(),
            'motivo'      => $motivo,
        ]);
    }

    /**
     * **Iniciar sesión cancela la baja programada** (decisión del usuario). Devuelve cuántas canceló.
     *
     * Se llama en los dos logins (web y app), justo después de autenticar: si la persona sigue entrando, es
     * que no quiere que su cuenta desaparezca.
     */
    public function cancelarPorInicioDeSesion(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        $programadas = SolicitudDeBaja::where('user_id', $user->id)
            ->where('estado', SolicitudDeBaja::PROGRAMADA)
            ->get();

        foreach ($programadas as $solicitud) {
            $this->cancelar($solicitud, SolicitudDeBaja::MOTIVO_INICIO_DE_SESION);
        }

        return $programadas->count();
    }

    /** Las que ya vencieron y esperan su ejecución. */
    public function vencidas()
    {
        return SolicitudDeBaja::query()
            ->where('estado', SolicitudDeBaja::PROGRAMADA)
            ->whereNotNull('ejecutar_despues_de')
            ->where('ejecutar_despues_de', '<=', now())
            ->orderBy('ejecutar_despues_de');
    }

    /**
     * **Ejecutar una baja.** Con `$simular` no escribe ni borra nada: devuelve el resumen de lo que haría.
     *
     * Todo va en una transacción: si algo falla a mitad (una clave foránea, un archivo que no se puede
     * borrar), no queda una cuenta a medio borrar.
     *
     * @return array<string,mixed>
     */
    public function ejecutar(SolicitudDeBaja $solicitud, bool $simular = true): array
    {
        $user = User::find($solicitud->user_id);

        $medicos = $user !== null
            ? Medico::where('user_id', $user->id)->get()
            : Medico::where('email', $solicitud->email)->get();

        $ids = $medicos->pluck('id')->all();
        $registros = $medicos->pluck('reg_medico')->filter()->values()->all();

        $secretarias = $this->secretariasQueSoloTrabajanEn($ids);
        $resumen = [
            'solicitud'    => $solicitud->id,
            'email'        => $solicitud->email,
            'medicos'      => $medicos->count(),
            'registros'    => $registros,
            'secretarias'  => $secretarias->count(),
            'archivos'     => $this->carpetasDeArchivos($registros),
            'simulado'     => $simular,
        ];

        if ($simular) {
            return $resumen;
        }

        DB::transaction(function () use ($solicitud, $user, $medicos, $ids, $secretarias, $registros) {
            foreach ($registros as $registro) {
                Storage::disk(ArchivosClinicos::DISCO)->deleteDirectory(ArchivosClinicos::CARPETA . '/' . $this->limpiar($registro));
                Storage::disk(ArchivosClinicos::DISCO)->deleteDirectory('importadas/' . $this->limpiar($registro));
            }

            // Ojo con las dos listas: **los ids de médico no son ids de usuario**. Mezclarlas borraría
            // cuentas ajenas cuyo `users.id` coincida con un `medicos.id`.
            $usuariosABorrar = $secretarias->pluck('id')->all();

            if ($user !== null) {
                $usuariosABorrar[] = $user->id;
            }

            DB::table('medico_registros')->whereIn('medico_id', $ids)->delete();
            DB::table('medico_specialty')->whereIn('medico_id', $ids)->delete();
            DB::table('medico_subespecialidad')->whereIn('medico_id', $ids)->delete();
            DB::table('medico_medical_center')->whereIn('medico_id', $ids)->delete();
            DB::table('medico_pacientes')->whereIn('medico_id', $ids)->delete();
            Medico::whereIn('id', $ids)->delete();

            // Las secretarías que sólo trabajaban con estos médicos se van con ellos; la que también
            // trabaja en otro consultorio se queda (perdería el acceso a lo suyo).
            if ($usuariosABorrar !== []) {
                User::whereIn('id', array_values(array_unique($usuariosABorrar)))->delete();
            }

            $solicitud->update([
                'estado'       => SolicitudDeBaja::EJECUTADA,
                'ejecutada_en' => now(),
            ]);
        });

        return $resumen;
    }

    /** Las cuentas de secretaría cuyo único vínculo son estos médicos. */
    private function secretariasQueSoloTrabajanEn(array $medicosIds)
    {
        if ($medicosIds === []) {
            return collect();
        }

        $usuarios = MedicoRegistro::whereIn('medico_id', $medicosIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->values();

        return User::whereIn('id', $usuarios->all())
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['Medico', 'Root', 'Administrador']))
            ->get()
            ->filter(function (User $user) use ($medicosIds) {
                return MedicoRegistro::where('user_id', $user->id)
                    ->whereNotIn('medico_id', $medicosIds)
                    ->count() === 0;
            })
            ->values();
    }

    /** @return array<int,string> las carpetas del disco `clinica` que se borrarían */
    private function carpetasDeArchivos(array $registros): array
    {
        $carpetas = [];

        foreach ($registros as $registro) {
            $carpetas[] = ArchivosClinicos::CARPETA . '/' . $this->limpiar($registro);
            $carpetas[] = 'importadas/' . $this->limpiar($registro);
        }

        return $carpetas;
    }

    private function limpiar(string $registro): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $registro) ?: 'sin-registro';
    }
}
