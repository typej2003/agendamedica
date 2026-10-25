<?php

namespace App\Paciente;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use Carbon\Carbon;

/**
 * Una cita vista por el paciente: la fila ya resuelta por `ArmadorDeAgenda` (fecha, sede, estado) más los
 * datos que la pantalla necesita (el médico y el lugar) y si se puede gestionar.
 */
final class CitaDelPaciente
{
    public function __construct(
        public readonly CitaDeAgenda $cita,
        public readonly Medico $medico,
        public readonly ?MedicalCenter $centro,
        public readonly string $aviso,
    ) {
    }

    public function id(): int
    {
        return $this->cita->id;
    }

    public function fecha(): string
    {
        return $this->cita->fecha;
    }

    public function dia(): string
    {
        return ucfirst(Carbon::createFromFormat('!Y-m-d', $this->cita->fecha)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'));
    }

    public function diaNumero(): string
    {
        return Carbon::createFromFormat('!Y-m-d', $this->cita->fecha)->format('d');
    }

    public function mes(): string
    {
        return mb_strtoupper(Carbon::createFromFormat('!Y-m-d', $this->cita->fecha)->locale('es')->isoFormat('MMM'));
    }

    public function especialidades(): string
    {
        return $this->medico->specialties->pluck('name')->join(' · ') ?: 'Consulta médica';
    }

    public function hora(): string
    {
        return $this->cita->horaIni !== null ? substr((string) $this->cita->horaIni, 0, 5) : '—';
    }

    public function horaLegible(): string
    {
        return \App\Support\HoraLocal::reloj($this->hora(), '—');
    }

    public function inicio(): Carbon
    {
        $hora = $this->cita->horaIni !== null ? substr((string) $this->cita->horaIni, 0, 5) : '08:00';

        return Carbon::createFromFormat('Y-m-d H:i', $this->cita->fecha . ' ' . $hora, FechaDelConsultorio::zona());
    }

    /** ¿Todavía no llegó? Se mide contra el reloj del consultorio, no contra el día. */
    public function proxima(): bool
    {
        return ! $this->cita->atendida()
            && ! $this->cita->movida()
            && $this->inicio()->greaterThan(FechaDelConsultorio::ahora());
    }

    public function pasada(): bool
    {
        return ! $this->proxima();
    }

    public function puedeGestionarse(): bool
    {
        return $this->aviso === '';
    }

    public function sede(): string
    {
        return $this->centro->name ?? 'Consultorio';
    }

    public function direccion(): string
    {
        return $this->centro->address ?? '';
    }

    public function motivo(): string
    {
        return $this->cita->razon();
    }

    public function turno(): ?int
    {
        return $this->cita->numOrden;
    }

    public function estado(): string
    {
        if ($this->cita->atendida()) {
            return 'Atendida';
        }

        if ($this->cita->movida()) {
            return 'Movida por el consultorio';
        }

        return match ((int) $this->cita->estado) {
            Cola::ESTADO_CONFIRMADA_PACIENTE => 'Confirmada por ti',
            Cola::ESTADO_CONFIRMADA => 'Confirmada por el consultorio',
            default => 'Por confirmar',
        };
    }

    public function estadoClase(): string
    {
        if ($this->cita->atendida()) {
            return 'paciente-estado-atendida';
        }

        if ($this->cita->movida()) {
            return 'paciente-estado-movida';
        }

        return (int) $this->cita->estado === Cola::ESTADO_NO_CONFIRMADA
            ? 'paciente-estado-pendiente'
            : 'paciente-estado-confirmada';
    }
}
