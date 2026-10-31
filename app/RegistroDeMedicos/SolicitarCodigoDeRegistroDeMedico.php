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
    public function ejecutar(array $datos): array
    {
        $limpios = $this->limpiar($datos);
        $this->exigirLibres($limpios['email'], $limpios['cedula']);

        $anterior = $this->enCurso->datos();
        $mismoTramite = $anterior !== null
            && ($anterior['email'] ?? null) === $limpios['email']
            && ($anterior['expira'] ?? 0) > now()->timestamp
            && (int) ($anterior['intentos'] ?? 0) < 5;

        // La foto que llegó en el formulario ya está guardada y recordada (la guarda `recordarCarnet`, antes de
        // validar el resto): acá sólo se decide con cuál viaja la solicitud.
        $archivo = $this->archivoDelTramite($limpios['email']);

        if ($mismoTramite) {
            // Mismo correo: se reaprovecha el código y sólo se actualizan los datos, sin mandar otro correo.
            $this->enCurso->guardar(array_merge($anterior, $limpios, $archivo));

            return ['reenviado' => false];
        }

        // El correo puede fallar: la foto **no** se borra, el trámite está a medio hacer y se retoma.
        $this->enviar(array_merge($limpios, $archivo));

        return ['reenviado' => true];
    }

    /**
     * Guarda la foto que llegó en el formulario y la recuerda en la sesión, aparte del trámite.
     *
     * La llama el controlador **antes** de validar el resto de los campos: así un teléfono mal escrito, una
     * especialidad rechazada o un correo que no sale no obligan al médico a elegir la foto otra vez.
     *
     * @throws \InvalidArgumentException si el archivo no es de un tipo aceptado (el controlador ya lo validó)
     */
    public function recordarCarnet(UploadedFile $archivo, string $email): void
    {
        $anterior = $this->enCurso->carnet();
        $nuevo = $this->carnet->guardar($archivo);

        if ($anterior !== null) {
            $this->carnet->borrar($anterior['carnet_ruta'] ?? null);
        }

        $this->enCurso->recordarCarnet($nuevo + ['email' => $this->correoNormalizado($email)]);
    }

    /**
     * La foto que viaja con la solicitud: la recordada de **este** correo, o nada.
     *
     * La de un trámite de otro correo (se empezó de nuevo con otra dirección) se borra: no queda huérfana ni
     * se le adjunta a otra persona.
     *
     * @return array<string,mixed>
     */
    private function archivoDelTramite(string $email): array
    {
        $recordada = $this->enCurso->carnet();

        if ($recordada === null) {
            return [];
        }

        if (($recordada['email'] ?? null) !== $email) {
            $this->carnet->borrar($recordada['carnet_ruta'] ?? null);
            $this->enCurso->olvidarCarnet();

            return [];
        }

        return array_intersect_key($recordada, array_flip(['carnet_ruta', 'carnet_tipo', 'carnet_nombre']));
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
            'email' => $this->correoNormalizado((string) $datos['email']),
            'ciudad' => $this->textoONull($datos['ciudad'] ?? null),
            'mensaje' => $this->textoONull($datos['mensaje'] ?? null),
            'codigo_referido' => $this->codigoDeReferido($datos['codigo_referido'] ?? null),
        ];
    }

    /** El código de invitación se guarda en mayúsculas; si no vino, null. Se valida al confirmar la solicitud. */
    private function codigoDeReferido($valor): ?string
    {
        $codigo = strtoupper(trim((string) $valor));

        return $codigo === '' ? null : substr($codigo, 0, 12);
    }

    /**
     * El correo no puede ser el de una **cuenta de médico** (una `User` con el rol o una ficha con cuenta): ese
     * ya está registrado y entra a trabajar.
     *
     * Una cuenta **que no es de médico** —paciente, administración, secretaría— **sí** puede registrarse: al
     * aprobar se le habilita el área médica en su misma cuenta (misma clave) y el login le pregunta en cuál
     * quiere entrar (2026-10-24). Una **ficha del escritorio sin cuenta** tampoco bloquea: el administrador le
     * da acceso a esa ficha al aprobar. La cédula sí bloquea si está en una solicitud de otro correo.
     */
    private function exigirLibres(string $email, string $cedula): void
    {
        $cuenta = User::whereRaw('LOWER(email) = ?', [$email])->first();

        $yaEsMedico = ($cuenta !== null && ($cuenta->hasRole('Medico') || $cuenta->medico()->exists()))
            || Medico::whereRaw('LOWER(email) = ?', [$email])->whereNotNull('user_id')->exists();

        if ($yaEsMedico) {
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya es de una cuenta de médico. Inicia sesión; si no recuerdas la contraseña, recupérala desde el inicio de sesión.',
            ]);
        }

        $cedulaDeOtra = SolicitudMedico::where('estado', SolicitudMedico::PENDIENTE)
            ->where('cedula', $cedula)
            ->whereRaw('LOWER(email) <> ?', [$email])
            ->exists();

        if ($cedulaDeOtra) {
            throw ValidationException::withMessages([
                'cedula' => 'Esa cédula ya tiene una solicitud en revisión con otro correo.',
            ]);
        }
    }

    private function correoNormalizado(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function textoONull($valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
