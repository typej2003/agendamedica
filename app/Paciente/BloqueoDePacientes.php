<?php

namespace App\Paciente;

use App\Models\BloqueoDePaciente;
use App\Models\Paciente;
use App\Models\User;
use App\Notificaciones\Telefono;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * **El bloqueo de una cuenta de paciente** (los que crean cuenta propia y agendan por el portal, la reserva
 * pública o el chatbot; no los pacientes clínicos de los médicos). Es la regla única: el panel bloquea y
 * desbloquea con `bloquear()`/`desbloquear()`, y cada punto de entrada que crea citas pregunta
 * `estaBloqueado()`/`exigirQueNoEsteBloqueado()`.
 *
 * ## Identidad por niveles (`NivelesDeIdentidad`)
 *
 * La cédula que escribe el propio paciente puede ser inventada y los teléfonos se reasignan con el tiempo, así
 * que **qué se bloquea depende de cuánta identidad tiene la cuenta**:
 *
 *  - Nivel 0 (sin validar): sólo la cuenta.
 *  - Nivel 1 (teléfono validado por código y vigente): cuenta + teléfono, con vencimiento (`identidad.bloqueo_telefono_meses`).
 *  - Nivel 2 (cédula confirmada por el personal): cuenta + teléfono vigente + cédula.
 *
 * La fuente de verdad son las filas de `bloqueos_de_paciente` (una por alcance). Además sigue valiendo el
 * bloqueo de cuenta de siempre (`users.is_active = false` + `blocked_reason`), así que el inicio de sesión y las
 * sesiones abiertas lo respetan (`EnsureAccountActive`); las cuentas bloqueadas antes de este esquema quedan
 * bloqueadas como cuenta.
 *
 * ## Coincidencia (`estaBloqueado`)
 *
 * Alguien está bloqueado si: (a) su cuenta está bloqueada; o (b) su teléfono **validado y vigente** coincide con un
 * bloqueo de teléfono vigente (un teléfono sin validar o con la validación vencida no se compara); o (c) su cédula
 * coincide con un bloqueo por cédula **y esa cédula está confirmada por personal** (la cédula tecleada por el
 * paciente no bloquea por sí sola).
 *
 * El bloqueo aplica a lo que el paciente hace **por su cuenta** (portal «Mis citas», reserva pública) y por el
 * chatbot de WhatsApp. La secretaría y el médico agendan desde la web clínica sin pasar por esta regla.
 */
final class BloqueoDePacientes
{
    public const AVISO = 'Tu cuenta de paciente está bloqueada. Comunícate con el consultorio.';

    private NivelesDeIdentidad $niveles;

    public function __construct(?NivelesDeIdentidad $niveles = null)
    {
        $this->niveles = $niveles ?? new NivelesDeIdentidad();
    }

    /**
     * Qué se bloqueará hoy para esa cuenta, según su nivel: `cuenta` (siempre), `telefono` (normalizado, si hay
     * validación vigente) y `cedula` (dígitos, si está confirmada por personal).
     *
     * @return array{nivel:int,cuenta:bool,telefono:?string,cedula:?string}
     */
    public function alcancesDe(User $user): array
    {
        $ficha = $user->paciente;
        $confirmada = $this->niveles->fichaConfirmada($ficha);

        return [
            'nivel' => $this->niveles->nivel($user),
            'cuenta' => true,
            'telefono' => $this->niveles->telefonoValidadoVigente($user),
            'cedula' => $confirmada ? NivelesDeIdentidad::digitos($ficha->cedula) : null,
        ];
    }

    public function bloquear(User $paciente, string $motivo = '', ?User $actor = null): void
    {
        $this->exigirCuentaDePaciente($paciente);

        $motivo = trim($motivo);
        $alcances = $this->alcancesDe($paciente);

        DB::transaction(function () use ($paciente, $motivo, $actor, $alcances) {
            $paciente->forceFill([
                'is_active' => false,
                'blocked_reason' => $motivo !== '' ? $motivo : null,
                'blocked_at' => now(),
                'blocked_by' => $actor?->id,
            ])->save();
            $paciente->tokens()->delete();

            // Volver a bloquear renueva: se levantan los anteriores de esta persona y se escriben los de hoy.
            BloqueoDePaciente::vigentes()->where('user_id', $paciente->id)
                ->update(['levantado_en' => now(), 'levantado_por' => $actor?->id]);

            $base = ['user_id' => $paciente->id, 'motivo' => $motivo !== '' ? $motivo : null, 'creado_por' => $actor?->id, 'creado_en' => now()];

            BloqueoDePaciente::create($base);

            if ($alcances['telefono'] !== null) {
                BloqueoDePaciente::create($base + [
                    'telefono' => $alcances['telefono'],
                    'vence_en' => now()->addMonthsNoOverflow(NivelesDeIdentidad::mesesDeBloqueoPorTelefono()),
                ]);
            }

            if ($alcances['cedula'] !== null) {
                BloqueoDePaciente::create($base + ['cedula' => $alcances['cedula']]);
            }
        });

        Log::info('Cuenta de paciente bloqueada.', ['paciente_user_id' => $paciente->id, 'por' => $actor?->id, 'motivo' => $motivo, 'nivel' => $alcances['nivel']]);
    }

    public function desbloquear(User $paciente, ?User $actor = null): void
    {
        $this->exigirCuentaDePaciente($paciente);

        DB::transaction(function () use ($paciente, $actor) {
            $paciente->forceFill(['is_active' => true, 'blocked_reason' => null, 'blocked_at' => null, 'blocked_by' => null])->save();

            BloqueoDePaciente::whereNull('levantado_en')->where('user_id', $paciente->id)
                ->update(['levantado_en' => now(), 'levantado_por' => $actor?->id]);
        });

        Log::info('Cuenta de paciente desbloqueada.', ['paciente_user_id' => $paciente->id, 'por' => $actor?->id]);
    }

    /**
     * ¿Está bloqueado quien hace la reserva? Ver la regla (a), (b) y (c) arriba.
     *
     * @param  User|null    $user              la cuenta, si hay sesión
     * @param  string|null  $telefonoValidado  un teléfono que **acaba de validarse** en este trámite (el código de la
     *                                         reserva pública, o el número de WhatsApp, que Meta ya autenticó). Nunca uno sin validar.
     * @param  string|null  $cedula            la cédula que trae; sólo bloquea si está confirmada por personal
     */
    public function estaBloqueado(?User $user = null, ?string $telefonoValidado = null, ?string $cedula = null): bool
    {
        $telefonos = [];
        $cedulas = [];

        if ($user !== null) {
            if ($user->is_active === false || $this->cuentaConBloqueoVigente((int) $user->id)) {
                return true;
            }

            if (($propio = $this->niveles->telefonoValidadoVigente($user)) !== null) {
                $telefonos[] = $propio;
            }

            $ficha = $user->paciente;
            if ($this->niveles->fichaConfirmada($ficha)) {
                $cedulas[] = NivelesDeIdentidad::digitos($ficha->cedula);
            }
        }

        if (($validado = Telefono::internacional($telefonoValidado)) !== null) {
            $telefonos[] = $validado;
        }

        if ($this->niveles->cedulaConfirmada($cedula)) {
            $cedulas[] = NivelesDeIdentidad::digitos($cedula);
        }

        if ($telefonos === [] && $cedulas === []) {
            return false;
        }

        return BloqueoDePaciente::vigentes()
            ->where(function ($q) use ($telefonos, $cedulas) {
                if ($telefonos !== []) {
                    $q->orWhereIn('telefono', array_values(array_unique($telefonos)));
                }
                if ($cedulas !== []) {
                    $q->orWhereIn('cedula', array_values(array_unique($cedulas)));
                }
            })
            ->exists();
    }

    /**
     * El chatbot: el número de WhatsApp ya está autenticado por Meta, así que cuenta como teléfono validado
     * (se compara con los bloqueos de teléfono vigentes). La ficha que se encontró por ese número sólo aporta su
     * cuenta y su cédula si esa cuenta tiene **ese mismo teléfono con la validación vigente**: el teléfono que
     * dice la ficha no está validado y puede ser de otra persona.
     */
    public function estaBloqueadoPorWhatsApp(string $telefono, ?Paciente $ficha = null): bool
    {
        $cuenta = $ficha?->user_id ? User::role('Paciente')->find($ficha->user_id) : null;

        if ($cuenta !== null && $this->niveles->telefonoValidadoVigente($cuenta) !== Telefono::internacional($telefono)) {
            $cuenta = null;
        }

        return $this->estaBloqueado($cuenta, $telefono);
    }

    /** @throws ValidationException con el aviso al paciente bloqueado */
    public function exigirQueNoEsteBloqueado(?User $user = null, ?string $telefonoValidado = null, ?string $cedula = null, string $campo = 'cedula'): void
    {
        if ($this->estaBloqueado($user, $telefonoValidado, $cedula)) {
            throw ValidationException::withMessages([$campo => self::AVISO]);
        }
    }

    private function cuentaConBloqueoVigente(int $userId): bool
    {
        return BloqueoDePaciente::vigentes()->where('user_id', $userId)->whereNull('telefono')->whereNull('cedula')->exists();
    }

    /** Una cuenta de paciente puro: no se bloquea con esto a un administrador, un médico ni una secretaría. */
    private function exigirCuentaDePaciente(User $user): void
    {
        if (! $user->hasRole('Paciente') || $user->esAdministrador() || $user->hasAnyRole(['Medico', 'Secretaria'])) {
            throw ValidationException::withMessages(['cuenta' => 'Esta cuenta no es de un paciente: se administra desde Médicos o Administradores.']);
        }
    }
}
