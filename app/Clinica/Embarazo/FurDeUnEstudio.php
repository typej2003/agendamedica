<?php

namespace App\Clinica\Embarazo;

use Illuminate\Support\Facades\DB;

/**
 * **La FUR que ya tiene un estudio ecográfico de la historia** (2026-10-22): el camino de vuelta del que usa
 * `FurDelControlPrenatal`.
 *
 * La FUR es **la misma para la misma gesta**: si el médico hizo el eco primero y tecleó la FUR ahí, la
 * gesta nueva no se la tiene que volver a preguntar. «Nueva gesta» sólo pregunta cuántos bebés son
 * (`CrearGestaDelEmbarazo`), así que la FUR se completa al crear la fila, **sin preguntar**.
 *
 * Dos decisiones:
 *
 *  - busca en **todos los estudios que llevan FUR** —el pélvico, los dos obstétricos del 1.º y del
 *    2.º/3.er trimestre (dos tablas), el Doppler y el ecocardiograma fetal— y se queda con el **más
 *    reciente** por la fecha del estudio (y, a igual fecha, por `id`);
 *  - pide que esa FUR sea **posterior** a la de la gesta anterior (`despuesDe`), cuando la hay: un eco de un
 *    embarazo que ya terminó no le pone la FUR al que empieza. Sin gesta anterior, o con una anterior sin
 *    FUR, cualquier estudio con FUR sirve.
 *
 * `eco_pelvico` llavea la historia por `numhistoria`; los demás, por `historia` (la rareza del legado).
 */
final class FurDeUnEstudio
{
    /**
     * Las tablas de los estudios con FUR, cada una con su columna de historia.
     *
     * @var array<string,string>
     */
    private const ESTUDIOS = [
        'eco_pelvico' => 'numhistoria',
        'eco_obstetrico' => 'historia',
        'eco_obstetrico_tercer' => 'historia',
        'eco_obstetrico_tercer_2' => 'historia',
        'eco_doppler' => 'historia',
        'ecocadiograma_fetal' => 'historia',
    ];

    /**
     * La FUR del estudio más reciente de la historia, en `AAAA-MM-DD`, o `null` si ninguno tiene.
     *
     * @param  string|null  $despuesDe  la FUR de la gesta anterior: el estudio tiene que ser posterior a ella
     */
    public function de(string $regMedico, int $numhistoria, ?string $despuesDe = null): ?string
    {
        $elegido = null;

        foreach (self::ESTUDIOS as $tabla => $columnaDeLaHistoria) {
            $fila = DB::table($tabla)
                ->where('reg_medico', $regMedico)
                ->where($columnaDeLaHistoria, $numhistoria)
                ->whereNotNull('fur')
                ->when($despuesDe !== null, fn ($consulta) => $consulta->where('fur', '>', $despuesDe))
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->first(['fur', 'fecha', 'id']);

            if ($fila === null) {
                continue;
            }

            if ($elegido === null || $this->esMasReciente($fila, $elegido)) {
                $elegido = $fila;
            }
        }

        return $elegido === null ? null : (string) $elegido->fur;
    }

    /**
     * ¿`$fila` es un estudio más reciente que `$actual`? La fecha del estudio manda; a igual fecha, el `id`.
     * Una fila sin fecha va después de cualquier fecha (no es lo más nuevo que hay).
     */
    private function esMasReciente(object $fila, object $actual): bool
    {
        $fecha = (string) ($fila->fecha ?? '');
        $fechaActual = (string) ($actual->fecha ?? '');

        if ($fecha !== $fechaActual) {
            return $fecha > $fechaActual;
        }

        return (int) $fila->id > (int) $actual->id;
    }
}
