<?php

namespace App\RegistroDeMedicos;

use App\Models\Medico;
use App\Models\SolicitudMedico;
use App\Models\User;
use App\Notificaciones\Telefono;
use App\Reservas\CodigoDeVerificacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Primer paso del **registro de médicos** (ROADMAP §10.4, fase 2): comprobar que el correo y la cédula no
 * choquen con una cuenta o con otra solicitud en revisión y **mandar el código de 6 dígitos al correo**.
 *
 * No crea la solicitud: nace cuando el código se confirma (`ConfirmarSolicitudDeMedico`). Se verifica el correo
 * —y no el teléfono, como en el paciente— porque es a donde le llega la clave temporal al aprobarla: un correo
 * mal escrito dejaría al médico aprobado sin poder entrar.
 */
final class SolicitarCodigoDeRegistroDeMedico
{
    private const MINUTOS_DE_VALIDEZ = 15;
    private const SEGUNDOS_ENTRE_ENVIOS = 60;

    public function __construct(
        private SolicitudEnCurso $enCurso,
        private CodigoDeVerificacion $codigos,
        private CarnetDeLaSolicitud $carnet,
        private CorreosDeLaSolicitud $correos,
    ) {
    }

    /**
     * @param  array<string,mixed>  $datos  prefijo, nombres, apellidos, cedula, mpps, colegio, specialty_id,
     *                                       telefono, email, ciudad, mensaje
     * @return array{reenviado: bool}
     *
     * @throws ValidationException
     */
    public function ejecutar(array $datos, ?UploadedFile $carnet = null): array
    {
        $limpios = $this->limpiar($datos);
        $this->exigirLibres($limpios['email'], $limpios['cedula']);

        $anterior = $this->enCurso->datos();
        $mismoTramite = $anterior !== null
            && ($anterior['email'] ?? null) === $limpios['email']
            && ($anterior['expira'] ?? 0) > now()->timestamp
            && (int) ($anterior['intentos'] ?? 0) < 5;

        // La foto nueva reemplaza la anterior; si no mandó otra, se conserva la que ya había subido en este
        // mismo trámite. La de un trámite abandonado (otro correo, o vencido) se borra: no queda huérfana.
        $archivo = $carnet !== null ? $this->carnet->guardar($carnet) : null;
        if ($anterior !== null && ($archivo !== null || ! $mismoTramite)) {
            $this->carnet->borrar($anterior['carnet_ruta'] ?? null);
        }
        if ($archivo === null) {
            $archivo = $mismoTramite
                ? array_intersect_key($anterior, array_flip(['carnet_ruta', 'carnet_tipo', 'carnet_nombre']))
                : [];
        }

        if ($mismoTramite) {
            // Mismo correo: se reaprovecha el código y sólo se actualizan los datos, sin mandar otro correo.
            $this->enCurso->guardar(array_merge($anterior, $limpios, $archivo));

            return ['reenviado' => false];
        }

        try {
            $this->enviar(array_merge($limpios, $archivo));
        } catch (\Throwable $e) {
            // Sin correo no hay código y el trámite no se guarda: la foto recién subida quedaría huérfana en
            // el disco, y con el SMTP caído se junta una por intento.
            $this->carnet->borrar($archivo['carnet_ruta'] ?? null);

            throw $e;
        }

        return ['reenviado' => true];
    }

    /**
     * Reenviar el código de una solicitud en curso, con el mismo minuto de espera que el resto de los envíos.
     *
     * @param  array<string,mixed>  $enCurso
     *
     * @throws ValidationException
     */
    public function reenviar(array $enCurso): void
    {
        $espera = self::SEGUNDOS_ENTRE_ENVIOS - (now()->timestamp - (int) ($enCurso['enviado_en'] ?? 0));

        if ($espera > 0) {
            throw ValidationException::withMessages([
                'codigo' => 'Espera ' . $espera . ' segundo(s) antes de pedir otro código.',
            ]);
        }

        $this->enviar($enCurso);
    }

    /** @param array<string,mixed> $datos */
    private function enviar(array $datos): void
    {
        $codigo = $this->codigos->generar();

        if (! $this->correos->codigo((string) $datos['email'], (string) $datos['nombres'], $codigo)
            && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages([
                'email' => 'No pudimos enviarte el correo con el código. Vuelve a intentarlo en un momento.',
            ]);
        }

        $this->enCurso->guardar(array_merge($datos, [
            'codigo_hash' => Hash::make($codigo),
            // Sólo en local/testing: sin correo configurado el código se muestra en pantalla para probar el flujo.
            'codigo' => app()->environment(['local', 'testing']) ? $codigo : null,
            'expira' => now()->addMinutes(self::MINUTOS_DE_VALIDEZ)->timestamp,
            'intentos' => 0,
            'enviado_en' => now()->timestamp,
        ]));
    }

    /**
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function limpiar(array $datos): array
    {
        $cedula = preg_replace('/\D/', '', (string) ($datos['cedula'] ?? ''));
        if ($cedula === '') {
            throw ValidationException::withMessages(['cedula' => 'Escribe tu cédula (solo los números).']);
        }

        $mpps = trim((string) ($datos['mpps'] ?? ''));
        if (preg_replace('/\D/', '', $mpps) === '') {
            throw ValidationException::withMessages(['mpps' => 'Escribe tu número de registro del MPPS.']);
        }

        $telefono = Telefono::internacional((string) ($datos['telefono'] ?? ''));
        if ($telefono === null) {
            throw ValidationException::withMessages([
                'telefono' => 'Escribe un teléfono venezolano válido, por ejemplo 0412-1234567.',
            ]);
        }

        $especialidad = (int) ($datos['specialty_id'] ?? 0);
        if ($especialidad !== 0 && ! EspecialidadesDelRegistro::admite($especialidad)) {
            throw ValidationException::withMessages(['specialty_id' => 'Elige una especialidad de la lista.']);
        }

        return [
            'prefijo' => $this->textoONull($datos['prefijo'] ?? null),
            'nombres' => trim((string) $datos['nombres']),
            'apellidos' => trim((string) $datos['apellidos']),
            'cedula' => $cedula,
            'mpps' => $mpps,
            'colegio' => $this->textoONull($datos['colegio'] ?? null),
            'specialty_id' => $especialidad ?: null,
            'telefono' => $telefono,
            'email' => mb_strtolower(trim((string) $datos['email'])),
            'ciudad' => $this->textoONull($datos['ciudad'] ?? null),
            'mensaje' => $this->textoONull($datos['mensaje'] ?? null),
        ];
    }

    /**
     * El correo no puede tener ya una cuenta ni una ficha de médico; ni el correo ni la cédula, otra solicitud
     * en revisión. Una solicitud **rechazada** no impide volver a intentarlo.
     */
    private function exigirLibres(string $email, string $cedula): void
    {
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()
            || Medico::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya tiene una cuenta. Inicia sesión o usa otro correo.',
            ]);
        }

        $enRevision = SolicitudMedico::where('estado', SolicitudMedico::PENDIENTE)
            ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email])->orWhere('cedula', $cedula))
            ->exists();

        if ($enRevision) {
            throw ValidationException::withMessages([
                'email' => 'Ya tienes una solicitud en revisión. Te avisaremos por correo cuando la revisemos.',
            ]);
        }
    }

    private function textoONull($valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
