<?php

namespace App\Clinica\Equipo\Vistas;

use App\Models\InvitacionDeMedico;
use App\Models\Medico;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * La pantalla **Equipo** (`/clinica/equipo`, 2026-10-28): los médicos que comparten el consultorio, las
 * secretarías y las invitaciones pendientes.
 *
 * No decide permisos ni cupos: los recibe ya resueltos de `CupoDelPlan` y del controlador. Sólo arma las
 * filas y los textos.
 */
final class EquipoVista
{
    /**
     * @param  Collection<int,Medico>  $medicos
     * @param  Collection<int,User>  $secretarias
     * @param  Collection<int,InvitacionDeMedico>  $pendientes
     * @param  array{email:string,url:string}|null  $linkInvitacion
     */
    public function __construct(
        public readonly string $regMedico,
        public readonly ?Medico $duenio,
        public readonly Collection $medicos,
        public readonly Collection $secretarias,
        public readonly Collection $pendientes,
        public readonly bool $puedeGestionar,
        public readonly bool $puedeInvitar,
        public readonly ?string $motivoNoPuedeInvitar,
        public readonly ?array $linkInvitacion,
        public readonly ?string $planNombre,
        public readonly ?int $maxMedicos,
        public readonly ?int $maxSecretarias,
        public readonly int $usuarioId,
    ) {
    }

    public function titulo(): string
    {
        return 'Equipo';
    }

    public function subtitulo(): string
    {
        return 'Los médicos que comparten este consultorio y la secretaría.';
    }

    public function nombreEquipo(): string
    {
        return $this->duenio?->nombreMostrar ?: $this->regMedico;
    }

    public function esCentro(): bool
    {
        return (bool) ($this->duenio?->es_centro ?? false);
    }

    public function planNombre(): string
    {
        return $this->planNombre ?? 'Sin plan';
    }

    /** “1 de 2 médicos” o “2 médicos (sin límite)”. */
    public function cupoMedicos(): string
    {
        $total = $this->medicos->count();

        return $this->maxMedicos === null
            ? $total . ' médico' . ($total === 1 ? '' : 's') . ' (sin límite)'
            : $total . ' de ' . $this->maxMedicos . ' médico' . ($this->maxMedicos === 1 ? '' : 's');
    }

    /** “1 de 1 secretaría” o “1 secretaría (sin límite)”. */
    public function cupoSecretarias(): string
    {
        $total = $this->secretarias->count();

        return $this->maxSecretarias === null
            ? $total . ' secretaría' . ($total === 1 ? '' : 's') . ' (sin límite)'
            : $total . ' de ' . $this->maxSecretarias . ' secretaría' . ($this->maxSecretarias === 1 ? '' : 's');
    }

    /** @return array<int,array<string,mixed>> */
    public function filasMedicos(): array
    {
        return $this->medicos->map(function (Medico $medico) {
            $esDuenio = $this->duenio !== null && (int) $medico->id === (int) $this->duenio->id;
            $esTu = (int) ($medico->user_id ?? 0) === $this->usuarioId;

            return [
                'id' => (int) $medico->id,
                'nombre' => $medico->nombreMostrar ?: trim($medico->name . ' ' . $medico->lastname),
                'correo' => $medico->user?->email ?: (string) $medico->email,
                'registro' => (string) $medico->reg_medico,
                'esDuenio' => $esDuenio,
                'esTu' => $esTu,
                'puedeQuitar' => $this->puedeGestionar && ! $esDuenio,
            ];
        })->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function filasInvitaciones(): array
    {
        return $this->pendientes->map(fn (InvitacionDeMedico $invitacion) => [
            'id' => (int) $invitacion->id,
            'email' => (string) $invitacion->email,
            'enviada' => $invitacion->created_at?->format(\App\Support\Regionalizacion::formatoActual()) ?? '',
            'expira' => $invitacion->expira_el?->format(\App\Support\Regionalizacion::formatoActual()) ?? '',
            'url' => route('clinica.invitaciones', ['invitacion' => $invitacion->token]),
        ])->all();
    }

    public function aviso(): ?string
    {
        if (! $this->puedeGestionar) {
            return 'Sólo el médico dueño del consultorio puede invitar o quitar médicos.';
        }

        if (! $this->puedeInvitar) {
            return $this->motivoNoPuedeInvitar;
        }

        return null;
    }
}
