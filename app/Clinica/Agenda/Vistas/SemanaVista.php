<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\LecturaDeAgenda;
use App\Clinica\FechaDelConsultorio;
use App\Support\FechaClinica;
use Carbon\Carbon;

/** El cuerpo de la vista de semana: los siete días, de lunes a domingo, cada uno con sus jornadas. */
final class SemanaVista
{
    /** @param  array<int,JornadaVista>  $jornadas  todas las de la semana (sin arrastre) */
    public function __construct(
        private LecturaDeAgenda $lectura,
        private array $jornadas,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function dias(): array
    {
        $lunes = $this->lectura->parametros->fecha->copy()->startOfWeek(Carbon::MONDAY);
        $dias = [];

        for ($i = 0; $i < 7; $i++) {
            $dia = $lunes->copy()->addDays($i);
            $clave = $dia->toDateString();
            $delDia = array_values(array_filter($this->jornadas, fn (JornadaVista $jornada) => $jornada->fecha() === $clave));
            $citas = array_sum(array_map(fn (JornadaVista $jornada) => $jornada->cantidad(), $delDia));

            $dias[] = [
                'titulo'       => ucfirst(FechaClinica::tituloDia($dia)),
                'esHoy'        => $dia->toDateString() === FechaDelConsultorio::hoy()->toDateString(),
                'conteo'       => $citas . ($citas === 1 ? ' cita' : ' citas'),
                'url'          => route('clinica.agenda', ['vista' => 'dia', 'fecha' => $clave, 'sede' => $this->lectura->parametros->sedeId ?? 'todas']),
                'noLaborable'  => MesVista::marca($this->lectura->noLaborables->get($clave)),
                'jornadas'     => $delDia,
            ];
        }

        return $dias;
    }
}
