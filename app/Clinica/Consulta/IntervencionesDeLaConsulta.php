<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\IntervencionConsulta;
use Illuminate\Support\Collection;

/**
 * **Intervenciones** de la consulta de cirugía pediátrica (2026-10-19): la solapa `tabpage_21` del ítem «I.Q.».
 *
 * Son **varias filas por consulta** en `interven_consulta`, con la clave del legado `historia` + `consulta` +
 * `codigo_inter` (tres caracteres). Cada una tiene su descripción (`char(100)`) y un detalle libre.
 *
 * El código es del médico (no hay catálogo en la fuente): la pantalla lo pide y, si la fila ya existe, la edita.
 */
final class IntervencionesDeLaConsulta
{
    public const TABLA = 'interven_consulta';

    /** El largo del código en el esquema del legado (`char(3)`). */
    public const LARGO_CODIGO = 3;

    /** @return Collection<int,IntervencionConsulta> */
    public function de(Consulta $consulta): Collection
    {
        return IntervencionConsulta::where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->orderBy('codigo_inter')
            ->get();
    }

    public function fila(Consulta $consulta, string $codigo): ?IntervencionConsulta
    {
        return IntervencionConsulta::where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->where('codigo_inter', $this->codigo($codigo))
            ->first();
    }

    /** El código normalizado: en mayúsculas y con el largo de la columna. */
    public function codigo(string $codigo): string
    {
        return mb_strtoupper(mb_substr(trim($codigo), 0, self::LARGO_CODIGO));
    }

    public function filaNueva(Consulta $consulta, string $codigo): IntervencionConsulta
    {
        return IntervencionConsulta::create([
            'reg_medico'   => $consulta->reg_medico,
            'historia'     => (int) $consulta->numhistoria,
            'consulta'     => (int) $consulta->nroconsulta,
            'codigo_inter' => $this->codigo($codigo),
        ]);
    }
}
