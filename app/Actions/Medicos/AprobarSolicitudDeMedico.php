<?php

namespace App\Actions\Medicos;

use App\Models\Medico;
use App\Models\SolicitudMedico;
use App\Models\User;
use App\RegistroDeMedicos\CorreosDeLaSolicitud;
use App\Services\CuentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **Aprobar la solicitud de registro de un médico** (ROADMAP §10.4, fase 2): nace su cuenta con clave temporal y
 * se le avisa por correo.
 *
 * El `reg_medico` lo confirma el administrador (la bandeja propone `MPPS-<número>`). Hay tres casos:
 *
 *  - **No existe ninguna ficha con ese `reg_medico`**: se crea la ficha y la cuenta con `CuentaService::crearMedico`.
 *    El modelo dispara `MedicoRegistrado`, que le da el mes de prueba.
 *  - **Ya existe la ficha y no tiene cuenta** (un médico del escritorio que sincronizó y ahora se registra en la
 *    web): se le **da acceso a esa ficha** (`darAccesoAMedico`) en vez de crear otra, así trabaja con sus
 *    pacientes. Sólo si la ficha no tiene correo o tiene el mismo de la solicitud: si tiene otro, el
 *    administrador tiene que resolverlo antes (no se pisa el correo de una ficha que no sabemos de quién es).
 *  - **El correo ya es de una cuenta que no es de médico** (paciente, administración, secretaría, 2026-10-24): se
 *    le **habilita el área médica en esa misma cuenta** (`darRolDeMedico`), con su misma clave y sus mismos
 *    roles; el login le empieza a preguntar en qué área entrar. No se manda clave temporal.
 *
 * La especialidad se asigna después de crear la cuenta, con `asignarEspecialidades`, que le copia el catálogo
 * inicial (WEB-12.9). El correo no es parte de la transacción: si falla, la clave se le muestra al administrador.
 */
final class AprobarSolicitudDeMedico
{
    public function __construct(
        private CuentaService $cuentas,
        private CorreosDeLaSolicitud $correos,
    ) {
    }

    /**
     * @return array{medico: Medico, clave: string, enlazada: bool, correoEnviado: bool}
     *
     * @throws ValidationException
     */
    public function ejecutar(SolicitudMedico $solicitud, User $revisor, string $regMedico, ?int $especialidadId = null): array
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            throw ValidationException::withMessages(['reg_medico' => 'Escribe el reg_medico con el que va a trabajar.']);
        }

        $resultado = DB::transaction(function () use ($solicitud, $revisor, $regMedico) {
            $solicitud = SolicitudMedico::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            if (! $solicitud->estaPendiente()) {
                throw ValidationException::withMessages(['solicitud' => 'Esta solicitud ya fue revisada.']);
            }

            $ficha = Medico::where('reg_medico', $regMedico)->first();

            if ($ficha === null) {
                $this->exigirQueNoSeaUnaFichaDelEscritorio($solicitud);
            }

            if ($ficha !== null) {
                $r = $this->darAccesoALaFicha($ficha, $solicitud);
                $enlazada = true;
                $clave = $r['clave'];
            } elseif (($cuenta = $this->cuentaQueYaExiste($solicitud)) !== null) {
                // Ya es paciente, administración o secretaría: se le habilita el área médica **en su cuenta**,
                // sin crear otra ni cambiarle la clave (2026-10-24).
                $r = $this->cuentas->darRolDeMedico($cuenta, [
                    'name' => $solicitud->nombres,
                    'lastname' => $solicitud->apellidos,
                    'prefix' => $solicitud->prefijo,
                    'phone' => $solicitud->telefono,
                    'license_number' => $solicitud->mpps,
                    'reg_medico' => $regMedico,
                ]);
                $ficha = $r['medico'];
                $enlazada = false;
                $clave = null;
            } else {
                $r = $this->cuentas->crearMedico([
                    'name' => $solicitud->nombres,
                    'lastname' => $solicitud->apellidos,
                    'prefix' => $solicitud->prefijo,
                    'email' => $solicitud->email,
                    'reg_medico' => $regMedico,
                    'phone' => $solicitud->telefono,
                    'license_number' => $solicitud->mpps,
                ]);
                $ficha = $r['medico'];
                $enlazada = false;
                $clave = $r['clave'];
            }

            $solicitud->update([
                'estado' => SolicitudMedico::APROBADA,
                'revisada_por' => $revisor->id,
                'revisada_el' => now(),
                'medico_id' => $ficha->id,
                'reg_medico' => $regMedico,
            ]);

            return ['medico' => $ficha, 'clave' => $clave, 'enlazada' => $enlazada, 'solicitud' => $solicitud];
        });

        // Va después de guardar, igual que en la pantalla de especialidades: si la copia del catálogo inicial
        // fallara, la cuenta igual queda creada.
        $especialidadId ??= $resultado['solicitud']->specialty_id;
        if ($especialidadId) {
            $actuales = $resultado['medico']->specialties()->pluck('specialties.id')->all();
            $this->cuentas->asignarEspecialidades($resultado['medico'], array_merge($actuales, [$especialidadId]));
        }

        $correoEnviado = $this->correos->aprobada($resultado['solicitud'], $resultado['clave']);

        return [
            'medico' => $resultado['medico'],
            'clave' => $resultado['clave'],
            'enlazada' => $resultado['enlazada'],
            'correoEnviado' => $correoEnviado,
        ];
    }

    /**
     * La cuenta que **ya existe** con el correo de la solicitud, si la hay (sin ser de médico: eso lo bloquea el
     * propio registro). Si esa cuenta ya tuviera una ficha de médico, no se crea otra: se le dice al
     * administrador cuál es su reg_medico.
     *
     * @throws ValidationException
     */
    private function cuentaQueYaExiste(SolicitudMedico $solicitud): ?User
    {
        $email = mb_strtolower(trim((string) $solicitud->email));

        if ($email === '') {
            return null;
        }

        $cuenta = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($cuenta === null) {
            return null;
        }

        $ficha = $cuenta->medico()->first();

        if ($ficha !== null) {
            throw ValidationException::withMessages([
                'reg_medico' => 'Ese correo ya tiene la ficha de médico con reg_medico «' . $ficha->reg_medico
                    . '». Confirma ese reg_medico para darle acceso a su historia.',
            ]);
        }

        return $cuenta;
    }

    /**
     * Una ficha del escritorio con el mismo correo y **sin cuenta** es la misma persona: el administrador tiene
     * que confirmar **su** `reg_medico` para darle acceso a su historia, en vez de crear una ficha nueva (que
     * `CuentaService::crearMedico` rechazaría por el correo repetido, con un error sin salida).
     *
     * @throws ValidationException
     */
    private function exigirQueNoSeaUnaFichaDelEscritorio(SolicitudMedico $solicitud): void
    {
        $email = mb_strtolower(trim((string) $solicitud->email));

        if ($email === '') {
            return;
        }

        $ficha = Medico::whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('user_id')
            ->whereNotNull('reg_medico')
            ->where('reg_medico', '!=', '')
            ->first();

        if ($ficha !== null) {
            throw ValidationException::withMessages([
                'reg_medico' => 'Ese correo ya es de la ficha del escritorio con reg_medico «' . $ficha->reg_medico
                    . '». Confirma ese reg_medico para darle acceso a su historia.',
            ]);
        }
    }

    /** @return array{user: User, clave: string} */
    private function darAccesoALaFicha(Medico $ficha, SolicitudMedico $solicitud): array
    {
        if ($ficha->es_centro) {
            throw ValidationException::withMessages([
                'reg_medico' => 'Ese reg_medico es de un centro médico. Usa uno propio del doctor y vincúlalo al centro desde «Cuentas».',
            ]);
        }

        if ($ficha->user_id && User::whereKey($ficha->user_id)->exists()) {
            throw ValidationException::withMessages([
                'reg_medico' => 'Ese reg_medico ya es de un médico con cuenta. Usa otro.',
            ]);
        }

        $correoDeLaFicha = mb_strtolower(trim((string) $ficha->email));
        if ($correoDeLaFicha !== '' && $correoDeLaFicha !== mb_strtolower($solicitud->email)) {
            throw ValidationException::withMessages([
                'reg_medico' => "La ficha de ese reg_medico tiene otro correo ({$ficha->email}). Corrígelo en «Cuentas» si es el mismo médico, o usa otro reg_medico.",
            ]);
        }

        // Se completa sólo lo que la ficha no tiene: lo que vino del escritorio no se pisa.
        $completar = ['email' => $solicitud->email];
        foreach (['phone' => $solicitud->telefono, 'license_number' => $solicitud->mpps, 'prefix' => $solicitud->prefijo] as $columna => $valor) {
            if (trim((string) $ficha->{$columna}) === '' && $valor) {
                $completar[$columna] = $valor;
            }
        }
        $ficha->forceFill($completar)->save();

        return $this->cuentas->darAccesoAMedico($ficha);
    }
}
