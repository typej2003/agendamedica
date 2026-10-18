<?php

namespace App\Clinica\Crecimiento;

use App\Models\Consulta;
use App\Models\PercentilesPaciente;

/**
 * **La lectura de las mediciones** (`percentiles_paciente`, WEB-7.4.3a): la fila de una consulta (clave `hhistoria` + `hconsulta`, del
 * `reg_medico` de la consulta) y todas las de una historia, que son los puntos de las gráficas (WEB-7.4.3c).
 */
class MedicionesDeLaConsulta
{
    /** La fila de esa consulta, o `null` si todavía no se midió nada. */
    public function de(Consulta $consulta): ?PercentilesPaciente
    {
        return PercentilesPaciente::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where('hhistoria', (int) $consulta->numhistoria)
            ->where('hconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /**
     * Todas las mediciones de una historia, de la más antigua a la más nueva (por fecha y, si no hay, por número de consulta).
     *
     * @return \Illuminate\Support\Collection<int,PercentilesPaciente>
     */
    public function deLaHistoria(string $regMedico, int $numhistoria)
    {
        return PercentilesPaciente::query()
            ->where('reg_medico', $regMedico)
            ->where('hhistoria', $numhistoria)
            ->orderBy('fecha')
            ->orderBy('hconsulta')
            ->get();
    }
}
