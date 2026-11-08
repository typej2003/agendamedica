<?php

namespace App\Clinica\Historia;

use Illuminate\Support\Facades\DB;

/**
 * **Las consultas de una historia que tienen evaluación pre-operatoria** (2026-11-07): el informe que valida al
 * paciente para quirófano es un documento más de la historia, así que se lista con los demás.
 *
 * Cuenta la consulta cuando tiene la estimación (`pre_aneste`), el resumen del riesgo (`riesgo_paciente_opera`) o una
 * intervención cargada (`interven_consulta`): la misma vara que `SolicitudesParaImprimir` y `DocumentosPorCita`.
 *
 * Es de sólo lectura.
 */
final class PreoperatoriosDeLaHistoria
{
    /**
     * @return array<int,int> los números de consulta, sin repetir
     */
    public function deLaHistoria(string $regMedico, int $numero): array
    {
        $numeros = [];

        foreach ([['pre_aneste', 'historia', 'consulta'], ['riesgo_paciente_opera', 'historia', 'consulta'], ['interven_consulta', 'historia', 'consulta']] as [$tabla, $historia, $consulta]) {
            foreach (DB::table($tabla)
                ->where('reg_medico', $regMedico)
                ->where($historia, $numero)
                ->distinct()
                ->pluck($consulta) as $nroconsulta) {
                $numeros[(int) $nroconsulta] = true;
            }
        }

        return array_keys($numeros);
    }
}
