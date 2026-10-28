<?php

namespace App\Services;

use App\Models\InvitacionDeMedico;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * **Invitaciones de un médico a otro para unirse a un equipo** (2026-10-28).
 *
 * La invitación es el consentimiento: no da acceso. El acceso se crea al aceptar, con la fila de
 * `medico_registros` que ya usa el resto de la aplicación. El cupo del plan se comprueba al invitar y se
 * **revalida al aceptar**, porque entre una cosa y la otra el equipo pudo llenarse.
 *
 * Todos los errores de negocio son `ValidationException` para que el panel y la web los muestren en el
 * formulario, sin inventar un tipo de excepción nuevo.
 */
final class InvitacionesDeEquipo
{
    public function __construct(private readonly CupoDelPlan $cupos)
    {
    }

    /**
     * Crea la invitación pendiente. No envía el correo: eso lo hace el controlador, para que un SMTP
     * caído no deshaga la invitación.
     *
     * @throws ValidationException
     */
    public function invitar(string $regMedico, string $email, User $invitador): InvitacionDeMedico
    {
        $regMedico = trim($regMedico);
        $email = mb_strtolower(trim($email));

        if ($regMedico === '') {
            throw ValidationException::withMessages(['email' => 'Falta el consultorio del equipo.']);
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Escribe un correo válido.']);
        }
        if (! Medico::where('reg_medico', $regMedico)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ese consultorio ya no existe.']);
        }
        if (mb_strtolower(trim((string) $invitador->email)) === $email) {
            throw ValidationException::withMessages(['email' => 'No puedes invitarte a tu propio equipo.']);
        }

        $cuenta = User::whereRaw('lower(email) = ?', [$email])->first();
        if ($cuenta !== null) {
            $medico = $cuenta->medico;
            if ($medico === null) {
                throw ValidationException::withMessages([
                    'email' => 'Ese correo tiene una cuenta que no es de médico. Pídele que se registre como médico o usa otro correo.',
                ]);
            }
            if ($medico->es_centro) {
                throw ValidationException::withMessages(['email' => 'Un centro médico no se une como médico.']);
            }
            if ($this->yaEstaEnElEquipo($medico, $regMedico)) {
                throw ValidationException::withMessages(['email' => 'Ese médico ya es parte del equipo.']);
            }
        }

        $pendiente = InvitacionDeMedico::where('reg_medico', $regMedico)
            ->where('email', $email)
            ->where('estado', InvitacionDeMedico::PENDIENTE)
            ->exists();

        if ($pendiente) {
            throw ValidationException::withMessages(['email' => 'Ya hay una invitación pendiente para ese correo en este equipo.']);
        }

        // El cupo se comprueba con el equipo actual; si hay invitaciones pendientes, la aceptación revalida.
        // El error va en `email` porque es el único campo del formulario de invitación.
        try {
            $this->cupos->exigirCupoMedico($regMedico);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'email' => $e->errors()['regNuevo'][0] ?? $e->getMessage(),
            ]);
        }

        return InvitacionDeMedico::create([
            'reg_medico' => $regMedico,
            'email' => $email,
            'medico_id' => $cuenta?->medico?->id,
            'invitada_por' => $invitador->id,
            'token' => Str::random(64),
            'estado' => InvitacionDeMedico::PENDIENTE,
            'expira_el' => now()->addDays(InvitacionDeMedico::DIAS_DE_VIGENCIA),
        ]);
    }

    /**
     * El invitado acepta: crea el vínculo de `medico_registros` y marca la invitación.
     *
     * @throws ValidationException
     */
    public function aceptar(InvitacionDeMedico $invitacion, User $invitado): MedicoRegistro
    {
        $this->exigirDestinatario($invitacion, $invitado);

        // Aceptar dos veces la misma invitación no duplica el vínculo ni da error si ya está adentro.
        if ($invitacion->estado === InvitacionDeMedico::ACEPTADA) {
            $medico = $invitado->medico;
            if ($medico !== null && ! $medico->es_centro && $this->yaEstaEnElEquipo($medico, (string) $invitacion->reg_medico)) {
                return MedicoRegistro::where('medico_id', $medico->id)
                    ->where('reg_medico', $invitacion->reg_medico)
                    ->firstOrFail();
            }
        }

        if ($invitacion->estado !== InvitacionDeMedico::PENDIENTE) {
            throw ValidationException::withMessages(['invitacion' => $this->mensajeDeEstado($invitacion)]);
        }
        if ($invitacion->expira_el !== null && $invitacion->expira_el->isPast()) {
            $invitacion->forceFill(['estado' => InvitacionDeMedico::VENCIDA])->save();

            throw ValidationException::withMessages(['invitacion' => 'Esta invitación venció. Pídele al médico que te envíe otra.']);
        }
        if (! Medico::where('reg_medico', $invitacion->reg_medico)->exists()) {
            throw ValidationException::withMessages(['invitacion' => 'El consultorio de la invitación ya no existe.']);
        }

        $medico = $invitado->medico;
        if ($medico === null) {
            throw ValidationException::withMessages([
                'invitacion' => 'Tu cuenta no tiene ficha de médico: completa tu registro de médico antes de aceptar.',
            ]);
        }
        if ($medico->es_centro) {
            throw ValidationException::withMessages(['invitacion' => 'Un centro médico no se une como médico a otro equipo.']);
        }

        // Si ya estaba vinculado, la aceptación es idempotente: no se duplica ni se revalida el cupo.
        if ($this->yaEstaEnElEquipo($medico, $invitacion->reg_medico)) {
            $this->marcarAceptada($invitacion, $medico);

            return MedicoRegistro::where('medico_id', $medico->id)
                ->where('reg_medico', $invitacion->reg_medico)
                ->firstOrFail();
        }

        // El equipo pudo llenarse desde que se envió la invitación.
        $this->cupos->exigirCupoMedico($invitacion->reg_medico);

        return DB::transaction(function () use ($invitacion, $medico) {
            $bloqueada = InvitacionDeMedico::whereKey($invitacion->id)->lockForUpdate()->first();

            if ($bloqueada === null || $bloqueada->estado !== InvitacionDeMedico::PENDIENTE) {
                throw ValidationException::withMessages([
                    'invitacion' => $bloqueada ? $this->mensajeDeEstado($bloqueada) : 'Esta invitación ya no existe.',
                ]);
            }

            $vinculo = MedicoRegistro::firstOrCreate([
                'medico_id' => $medico->id,
                'reg_medico' => $invitacion->reg_medico,
            ]);

            $bloqueada->forceFill([
                'estado' => InvitacionDeMedico::ACEPTADA,
                'medico_id' => $medico->id,
                'aceptada_el' => now(),
            ])->save();

            return $vinculo;
        });
    }

    /** El invitado rechaza: no se crea ningún vínculo y la invitación queda como historial. */
    public function rechazar(InvitacionDeMedico $invitacion, User $invitado): void
    {
        $this->exigirDestinatario($invitacion, $invitado);

        if ($invitacion->estado !== InvitacionDeMedico::PENDIENTE) {
            throw ValidationException::withMessages(['invitacion' => $this->mensajeDeEstado($invitacion)]);
        }

        $invitacion->forceFill(['estado' => InvitacionDeMedico::RECHAZADA])->save();
    }

    /** El dueño del equipo (o Root) revoca una invitación que todavía no se aceptó. */
    public function revocar(InvitacionDeMedico $invitacion): void
    {
        if ($invitacion->estado !== InvitacionDeMedico::PENDIENTE) {
            return;
        }

        $invitacion->forceFill(['estado' => InvitacionDeMedico::REVOCADA])->save();
    }

    /** @return Collection<int, InvitacionDeMedico> */
    public function pendientesPara(User $user): Collection
    {
        return InvitacionDeMedico::paraEmail((string) $user->email)
            ->pendientes()
            ->where('expira_el', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return Collection<int, InvitacionDeMedico> */
    public function pendientesDe(string $regMedico): Collection
    {
        return InvitacionDeMedico::where('reg_medico', $regMedico)
            ->pendientes()
            ->where('expira_el', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    private function exigirDestinatario(InvitacionDeMedico $invitacion, User $user): void
    {
        if (mb_strtolower(trim((string) $invitacion->email)) !== mb_strtolower(trim((string) $user->email))) {
            throw ValidationException::withMessages(['invitacion' => 'Esta invitación es para otro correo.']);
        }
    }

    private function yaEstaEnElEquipo(Medico $medico, string $regMedico): bool
    {
        return (string) $medico->reg_medico === $regMedico
            || MedicoRegistro::where('medico_id', $medico->id)->where('reg_medico', $regMedico)->exists();
    }

    private function marcarAceptada(InvitacionDeMedico $invitacion, Medico $medico): void
    {
        $invitacion->forceFill([
            'estado' => InvitacionDeMedico::ACEPTADA,
            'medico_id' => $medico->id,
            'aceptada_el' => now(),
        ])->save();
    }

    private function mensajeDeEstado(InvitacionDeMedico $invitacion): string
    {
        return match ($invitacion->estado) {
            InvitacionDeMedico::ACEPTADA => 'Esta invitación ya fue aceptada.',
            InvitacionDeMedico::RECHAZADA => 'Esta invitación ya fue rechazada.',
            InvitacionDeMedico::REVOCADA => 'Esta invitación fue revocada por el consultorio.',
            InvitacionDeMedico::VENCIDA => 'Esta invitación venció.',
            default => 'Esta invitación ya no está disponible.',
        };
    }
}
