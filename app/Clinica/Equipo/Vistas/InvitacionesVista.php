<?php

namespace App\Clinica\Equipo\Vistas;

use App\Models\InvitacionDeMedico;
use Illuminate\Support\Collection;

/**
 * La pantalla **Invitaciones** (`/clinica/invitaciones`, 2026-10-28): lo que un médico recibió para unirse
 * a otro equipo. Se entra desde el correo o desde el menú de la cuenta; no exige contexto clínico.
 */
final class InvitacionesVista
{
    /**
     * @param  Collection<int,InvitacionDeMedico>  $pendientes
     * @param  array{invitacion:InvitacionDeMedico,equipo:string,invitador:?string}|null  $destacada
     */
    public function __construct(
        public readonly Collection $pendientes,
        public readonly ?array $destacada,
        public readonly bool $tieneMedico,
        public readonly ?string $motivoSinMedico = null,
    ) {
    }

    public function titulo(): string
    {
        return 'Invitaciones';
    }

    public function subtitulo(): string
    {
        return 'Invitaciones para unirte al equipo de otro consultorio. Al aceptar compartes su agenda, sus pacientes y su secretaría.';
    }

    public function hay(): bool
    {
        return $this->pendientes->isNotEmpty() || $this->destacada !== null;
    }

    public function tieneMedico(): bool
    {
        return $this->tieneMedico;
    }

    public function motivoSinMedico(): ?string
    {
        return $this->motivoSinMedico;
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        $filas = $this->pendientes->map(fn (InvitacionDeMedico $invitacion) => [
            'invitacion' => $invitacion,
            'equipo' => $this->nombreDe($invitacion),
            'invitador' => $invitacion->invitador?->name,
            'puedeAceptar' => $this->tieneMedico && $invitacion->estaVigente(),
        ])->all();

        if ($this->destacada !== null && ! $this->pendientes->contains('id', $this->destacada['invitacion']->id)) {
            $invitacion = $this->destacada['invitacion'];
            $filas[] = [
                'invitacion' => $invitacion,
                'equipo' => $this->destacada['equipo'],
                'invitador' => $this->destacada['invitador'],
                'puedeAceptar' => $this->tieneMedico && $invitacion->estaVigente(),
            ];
        }

        return $filas;
    }

    private function nombreDe(InvitacionDeMedico $invitacion): string
    {
        $medico = \App\Models\Medico::where('reg_medico', $invitacion->reg_medico)->orderBy('id')->first();

        return $medico?->nombreMostrar ?: (string) $invitacion->reg_medico;
    }
}
