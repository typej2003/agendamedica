<?php

namespace App\Paciente;

use App\Models\Paciente;
use App\Models\User;
use App\Notificaciones\Telefono;
use App\Reservas\CodigoDeVerificacion;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Primer paso de la **cuenta del paciente**: comprobar que la cédula, el correo y el teléfono no choquen
 * con una cuenta o una ficha existente y **enviar el código de 6 dígitos**.
 *
 * No crea nada: el `User` y la ficha nacen cuando el código se confirma
 * (`ConfirmarRegistroDePaciente`). Si la cédula ya tiene ficha, el teléfono y el correo tienen que
 * coincidir con los registrados: el código prueba que controla el teléfono, y permitir uno distinto
 * dejaría apropiarse de la ficha de otra persona.
 */
final class SolicitarCodigoDeRegistro
{
    private const MINUTOS_DE_VALIDEZ = 10;
    private const SEGUNDOS_ENTRE_ENVIOS = 60;

    public function __construct(
        private RegistroPendiente $pendiente,
        private CodigoDeVerificacion $codigos,
    ) {
    }

    /**
     * @param  array<string,mixed>  $datos  cedula, nombres, apellidos, telefono, email, password
     * @return array{reenviado: bool, canal: string}
     *
     * @throws ValidationException
     */
    public function ejecutar(array $datos, bool $forzarReenvio = false): array
    {
        $cedula = preg_replace('/\D/', '', (string) $datos['cedula']);
        if ($cedula === '') {
            throw ValidationException::withMessages(['cedula' => 'Escribe tu cédula (solo los números).']);
        }

        $telefono = Telefono::internacional((string) $datos['telefono']);
        if ($telefono === null) {
            throw ValidationException::withMessages([
                'telefono' => 'Escribe un teléfono venezolano válido, por ejemplo 0412-1234567.',
            ]);
        }

        $email = mb_strtolower(trim((string) $datos['email']));
        $nombres = trim((string) $datos['nombres']);
        $apellidos = trim((string) $datos['apellidos']);

        if ($nombres === '' || $apellidos === '') {
            throw ValidationException::withMessages(['nombres' => 'Escribe tu nombre y tu apellido.']);
        }

        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya tiene una cuenta. Inicia sesión o usa otro correo.',
            ]);
        }

        $paciente = Paciente::where('cedula', $cedula)->first();

        if ($paciente !== null) {
            if ($paciente->user_id !== null && User::find($paciente->user_id) !== null) {
                throw ValidationException::withMessages([
                    'cedula' => 'Esa cédula ya tiene una cuenta. Inicia sesión.',
                ]);
            }

            $emailEnArchivo = mb_strtolower(trim((string) $paciente->email));
            if ($emailEnArchivo !== '' && $emailEnArchivo !== $email) {
                throw ValidationException::withMessages([
                    'email' => 'El correo no coincide con el que tenemos registrado. Si cambió, comunícate con el consultorio.',
                ]);
            }

            $telefonoEnArchivo = Telefono::internacional($paciente->telefono);
            if ($telefonoEnArchivo !== null && $telefonoEnArchivo !== $telefono) {
                throw ValidationException::withMessages([
                    'telefono' => 'El teléfono no coincide con el que tenemos registrado. Si cambió, comunícate con el consultorio.',
                ]);
            }
        }

        $passwordHash = Hash::make((string) $datos['password']);

        $anterior = $this->pendiente->datos();
        $mismoTramite = $anterior !== null
            && ($anterior['cedula'] ?? null) === $cedula
            && ($anterior['telefono'] ?? null) === $telefono
            && ($anterior['expira'] ?? 0) > now()->timestamp
            && (int) ($anterior['intentos'] ?? 0) < 5;

        if ($mismoTramite && ! $forzarReenvio) {
            // Misma persona y mismo teléfono: se reaprovecha el código y solo se actualizan los datos (por
            // ejemplo, si corrigió el nombre o la contraseña) sin gastar otro mensaje.
            $this->pendiente->guardar(array_merge($anterior, [
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'email' => $email,
                'password_hash' => $passwordHash,
            ]));

            return ['reenviado' => false, 'canal' => (string) ($anterior['canal'] ?? '')];
        }

        if ($mismoTramite && $forzarReenvio) {
            $espera = self::SEGUNDOS_ENTRE_ENVIOS - (now()->timestamp - (int) ($anterior['enviado_en'] ?? 0));

            if ($espera > 0) {
                throw ValidationException::withMessages([
                    'codigo' => 'Espera ' . $espera . ' segundo(s) antes de pedir otro código.',
                ]);
            }
        }

        $codigo = $this->codigos->generar();
        $envio = $this->codigos->enviar($telefono, $codigo);

        if (! $envio['ok']) {
            throw ValidationException::withMessages([
                'telefono' => $envio['error'] ?? 'No pudimos enviar el código. Intenta de nuevo en un momento.',
            ]);
        }

        $this->pendiente->guardar([
            'cedula' => $cedula,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'telefono' => $telefono,
            'email' => $email,
            'password_hash' => $passwordHash,
            'codigo_hash' => Hash::make($codigo),
            'codigo' => app()->environment(['local', 'testing']) ? $codigo : null,
            'expira' => now()->addMinutes(self::MINUTOS_DE_VALIDEZ)->timestamp,
            'intentos' => 0,
            'enviado_en' => now()->timestamp,
            'canal' => $envio['canal'],
        ]);

        return ['reenviado' => true, 'canal' => $envio['canal']];
    }

    /**
     * Reenviar el código de un registro que ya está en curso, sin volver a pedir la contraseña: el hash ya
     * está en la sesión. Respeta el mismo minuto de espera que el resto de los envíos.
     *
     * @param  array<string,mixed>  $pendiente
     * @return array{reenviado: bool, canal: string}
     *
     * @throws ValidationException
     */
    public function reenviar(array $pendiente): array
    {
        $espera = self::SEGUNDOS_ENTRE_ENVIOS - (now()->timestamp - (int) ($pendiente['enviado_en'] ?? 0));

        if ($espera > 0) {
            throw ValidationException::withMessages([
                'codigo' => 'Espera ' . $espera . ' segundo(s) antes de pedir otro código.',
            ]);
        }

        $codigo = $this->codigos->generar();
        $envio = $this->codigos->enviar((string) $pendiente['telefono'], $codigo);

        if (! $envio['ok']) {
            throw ValidationException::withMessages([
                'codigo' => $envio['error'] ?? 'No pudimos enviar el código. Intenta de nuevo en un momento.',
            ]);
        }

        $this->pendiente->guardar(array_merge($pendiente, [
            'codigo_hash' => Hash::make($codigo),
            'codigo' => app()->environment(['local', 'testing']) ? $codigo : null,
            'expira' => now()->addMinutes(self::MINUTOS_DE_VALIDEZ)->timestamp,
            'intentos' => 0,
            'enviado_en' => now()->timestamp,
            'canal' => $envio['canal'],
        ]));

        return ['reenviado' => true, 'canal' => $envio['canal']];
    }
}
