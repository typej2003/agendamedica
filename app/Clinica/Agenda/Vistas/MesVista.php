<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\LecturaDeAgenda;
use App\Clinica\FechaDelConsultorio;
use App\Models\DiaNoLaborable;
use Carbon\Carbon;

/**
 * El cuerpo de la vista de mes: semanas de siete días con el conteo de citas de cada uno. Incluye los
 * días de los meses vecinos que completan la primera y la última semana, marcados como fuera del mes.
 */
final class MesVista
{
    /** @param  array<string,mixed>  $query  los parámetros de la URL actual (cada día los conserva) */
    public function __construct(
        private LecturaDeAgenda $lectura,
        private array $query,
    ) {
    }

    /** @return array<int,string> */
    public function cabeceras(): array
    {
        return ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    public function semanas(): array
    {
        $fecha = $this->lectura->parametros->fecha;
        $cursor = $fecha->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $fin = $fecha->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $semanas = [];
        $semana = [];

        while ($cursor->lessThanOrEqualTo($fin)) {
            $clave = $cursor->toDateString();
            $conteo = (int) ($this->lectura->conteosPorDia[$clave] ?? 0);

            $semana[] = [
                'numero'      => $cursor->day,
                'esHoy'       => $cursor->toDateString() === FechaDelConsultorio::hoy()->toDateString(),
                'enMes'       => $cursor->month === $fecha->month,
                'conteo'      => $conteo,
                'conteoTexto' => $conteo . ($conteo === 1 ? ' cita' : ' citas'),
                // Cada día abre su vista de día conservando la sede y la razón que se estaban mirando.
                'url'         => route('clinica.agenda', array_merge($this->query, ['vista' => 'dia', 'fecha' => $clave])),
                // Se marca aunque no tenga citas: es el dato que el escritorio no muestra en su calendario
                // y la secretaria necesita.
                'noLaborable' => self::marca($this->lectura->noLaborables->get($clave)),
            ];

            if (count($semana) === 7) {
                $semanas[] = $semana;
                $semana = [];
            }

            $cursor->addDay();
        }

        return $semanas;
    }

    /** @return array{texto:string, titulo:string}|null */
    public static function marca(?DiaNoLaborable $dia): ?array
    {
        return $dia === null ? null : [
            'texto'  => $dia->etiquetaTipo(),
            'titulo' => $dia->motivo ?: $dia->etiquetaTipo(),
        ];
    }
}
