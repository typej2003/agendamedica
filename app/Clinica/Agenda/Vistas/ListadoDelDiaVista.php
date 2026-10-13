<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * El listado del día para imprimir (WEB-2.7): lo que reemplaza al `dw_1.print()` del botón *Imprimir* de
 * `w_hacer_cita` (`d_pacientes_consulta_cita_print`: hora, paciente, cédula, teléfono, razón y estatus,
 * sin las citas que el escritorio movió). Lo imprime el navegador (PLAN-WEB.md, R6).
 */
final class ListadoDelDiaVista
{
    /** @param  Collection<int,CitaDeAgenda>  $citas */
    public function __construct(
        private Carbon $fecha,
        private ?Office $sede,
        private string $medico,
        private Collection $citas,
    ) {
    }

    public function fecha(): string
    {
        return $this->fecha->format('d/m/Y');
    }

    /** "Dr. Pérez · San José (C1) · 05/10/2026". */
    public function subtitulo(): string
    {
        $sede = null;

        if ($this->sede) {
            $sede = ($this->sede->medicalCenter->name ?? 'Sede')
                . ($this->sede->office_number ? ' (' . $this->sede->office_number . ')' : '');
        }

        return collect([$this->medico, $sede, $this->fecha()])->filter()->implode(' · ');
    }

    public function estaVacio(): bool
    {
        return $this->citas->isEmpty();
    }

    public function total(): string
    {
        return $this->citas->count() . ' Pacientes para el ' . $this->fecha();
    }

    /** @return array<int,array<string,string>> */
    public function filas(): array
    {
        return $this->citas->values()->map(fn (CitaDeAgenda $cita, int $i) => [
            'numero'   => (string) ($i + 1),
            'hora'     => $cita->horaCorta(),
            'paciente' => $cita->paciente,
            'cedula'   => $cita->cedula ?: '—',
            'telefono' => $cita->telefono ?: '—',
            'razon'    => $cita->razon(),
            'estatus'  => $cita->atendida() ? 'Atendido' : 'Pendiente',
        ])->all();
    }
}
