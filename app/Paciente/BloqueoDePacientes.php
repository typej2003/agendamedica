<?php

namespace App\Paciente;

use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * **El bloqueo de una cuenta de paciente** (los que crean cuenta propia y agendan por el portal, la reserva
 * pública o el chatbot; no los pacientes clínicos de los médicos). Es la regla única: el panel bloquea y
 * desbloquea con `bloquear()`/`desbloquear()`, y cada punto de entrada que crea citas pregunta
 * `exigirQueNoEsteBloqueado()` con la cédula que trae.
 *
 * El bloqueo reutiliza el de las cuentas (`users.is_active = false` + `blocked_reason`), así que el inicio de
 * sesión y las sesiones abiertas ya lo respetan (`EnsureAccountActive`). Lo que se agrega aquí es (1) la
 * auditoría (`blocked_at`, `blocked_by` y una línea de registro) y (2) el cierre de la puerta sin sesión: la
 * reserva pública y el chatbot no tienen cuenta, así que se reconoce al paciente por la cédula de su ficha.
 */
final class BloqueoDePacientes
{
    public const AVISO = 'Tu cuenta de paciente está bloqueada y por ahora no puedes reservar citas. Si crees que es un error, comunícate con soporte de Doctorísimo.';

    public function bloquear(User $paciente, string $motivo = '', ?User $actor = null): void
    {
        $this->exigirCuentaDePaciente($paciente);

        $motivo = trim($motivo);

        DB::transaction(function () use ($paciente, $motivo, $actor) {
            $paciente->forceFill([
                'is_active' => false,
                'blocked_reason' => $motivo !== '' ? $motivo : null,
                'blocked_at' => now(),
                'blocked_by' => $actor?->id,
            ])->save();
            $paciente->tokens()->delete();
        });

        Log::info('Cuenta de paciente bloqueada.', ['paciente_user_id' => $paciente->id, 'por' => $actor?->id, 'motivo' => $motivo]);
    }

    public function desbloquear(User $paciente, ?User $actor = null): void
    {
        $this->exigirCuentaDePaciente($paciente);

        $paciente->forceFill(['is_active' => true, 'blocked_reason' => null, 'blocked_at' => null, 'blocked_by' => null])->save();

        Log::info('Cuenta de paciente desbloqueada.', ['paciente_user_id' => $paciente->id, 'por' => $actor?->id]);
    }

    /**
     * La cuenta de paciente bloqueada que corresponde a esta cédula, o `null`. Se reconoce **sólo por la
     * cédula** de la ficha enlazada a la cuenta (`pacientes.user_id`): ni el teléfono ni el correo identifican
     * a una persona (los teléfonos se reutilizan y se comparten), así que no se comparan.
     */
    public function cuentaBloqueada(?string $cedula): ?User
    {
        $cedulas = $this->variantesDeCedula($cedula);

        if ($cedulas === []) {
            return null;
        }

        $fichas = Paciente::query()
            ->whereNotNull('user_id')
            ->whereIn(DB::raw("UPPER(REPLACE(REPLACE(REPLACE(cedula, '-', ''), '.', ''), ' ', ''))"), $cedulas)
            ->pluck('user_id');

        return User::role('Paciente')
            ->where('is_active', false)
            ->whereIn('id', $fichas)
            ->first();
    }

    /** @throws ValidationException con el aviso al paciente bloqueado */
    public function exigirQueNoEsteBloqueado(?string $cedula, string $campo = 'cedula'): void
    {
        if ($this->cuentaBloqueada($cedula) !== null) {
            throw ValidationException::withMessages([$campo => self::AVISO]);
        }
    }

    /** Una cuenta de paciente puro: no se bloquea con esto a un administrador, un médico ni una secretaría. */
    private function exigirCuentaDePaciente(User $user): void
    {
        if (! $user->hasRole('Paciente') || $user->esAdministrador() || $user->hasAnyRole(['Medico', 'Secretaria'])) {
            throw ValidationException::withMessages(['cuenta' => 'Esta cuenta no es de un paciente: se administra desde Médicos o Administradores.']);
        }
    }

    /** @return array<int,string> */
    private function variantesDeCedula(?string $cedula): array
    {
        $digitos = preg_replace('/\D/', '', (string) $cedula);

        return $digitos === '' ? [] : [$digitos, 'V' . $digitos, 'E' . $digitos, 'J' . $digitos];
    }
}
