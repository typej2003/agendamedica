<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use Illuminate\Support\Facades\DB;

/**
 * **El historial de resúmenes anteriores** del examen (WEB-11.9, G3 de la revisión): lo que se escribió en
 * `examen_fisico_nuevo.total_g` (o `total_f`) en **cada consulta anterior** de la historia, de sólo lectura.
 *
 * El escritorio los lista en `d_evolucion_ginecologica.srd` con un rótulo que usa `nroconsulta − 1` (la 2.ª se llama
 * «1 Evolución de 1»); la web no copia ese corrimiento: rotula con el número real de la consulta y su fecha.
 * Las consultas se cruzan con el examen por `(historia, consulta) = (numhistoria, nroconsulta)` (ver `ExamenFisicoDeLaConsulta::filaDe`).
 */
final class ResumenesAnteriores
{
    /**
     * @param  string  $columna  `total_g` (ginecológico) o `total_f` (físico)
     * @return array<int,array{numero:int, fecha:?string, texto:string}> las anteriores, la más reciente primero; las vacías se omiten
     */
    public function de(Consulta $consulta, string $columna = 'total_g'): array
    {
        if (! in_array($columna, EvaluacionFisica::COLUMNAS_RESUMEN, true) || (int) $consulta->numhistoria <= 0) {
            return [];
        }

        $filas = DB::table('consultas as c')
            ->join('examen_fisico_nuevo as e', function ($join) {
                $join->on('e.historia', '=', 'c.numhistoria')
                    ->on('e.consulta', '=', 'c.nroconsulta')
                    ->on('e.reg_medico', '=', 'c.reg_medico');
            })
            ->where('c.reg_medico', $consulta->reg_medico)
            ->where('c.numhistoria', (int) $consulta->numhistoria)
            ->where('c.nroconsulta', '<', (int) $consulta->nroconsulta)
            ->orderByDesc('c.nroconsulta')
            ->get(['c.nroconsulta', 'c.fecha', 'e.' . $columna . ' as texto']);

        $resumenes = [];
        foreach ($filas as $fila) {
            $texto = trim((string) $fila->texto);
            if ($texto === '' || strcasecmp($texto, 'none') === 0) {
                continue;
            }
            $resumenes[] = [
                'numero' => (int) $fila->nroconsulta,
                'fecha' => $fila->fecha ? date('d/m/Y', strtotime((string) $fila->fecha)) : null,
                'texto' => $texto,
            ];
        }

        return $resumenes;
    }
}
