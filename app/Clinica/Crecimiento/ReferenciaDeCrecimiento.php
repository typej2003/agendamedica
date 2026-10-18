<?php

namespace App\Clinica\Crecimiento;

use Illuminate\Support\Facades\DB;

/**
 * **Lee la referencia de crecimiento** (WEB-7.4.3b): las tablas de percentiles que el escritorio de pediatría trae en su carga inicial
 * (`percentil_nino`, `percentil_nina`, `percentil_pa_sisto_m`, `percentil_pa_diast_m`, `percentil_pulso_a_m`). Son catálogos, sin datos de
 * pacientes. Se leen las filas del `reg_medico` del contexto y, si ese consultorio no las cargó, las que no tienen dueño (`reg_medico` nulo).
 * Si no hay ninguna, el servicio queda sin referencia y las pantallas lo dicen.
 */
class ReferenciaDeCrecimiento
{
    private const TABLAS = [
        'nino' => 'percentil_nino',
        'nina' => 'percentil_nina',
        PercentilesDeCrecimiento::SISTOLICA => 'percentil_pa_sisto_m',
        PercentilesDeCrecimiento::DIASTOLICA => 'percentil_pa_diast_m',
        PercentilesDeCrecimiento::PULSO => 'percentil_pulso_a_m',
    ];

    public function cargar(string $regMedico): PercentilesDeCrecimiento
    {
        $filas = [];
        foreach (self::TABLAS as $clave => $tabla) {
            $filas[$clave] = $this->filas($tabla, $regMedico);
        }

        return new PercentilesDeCrecimiento(
            $filas['nino'],
            $filas['nina'],
            [
                PercentilesDeCrecimiento::SISTOLICA => $filas[PercentilesDeCrecimiento::SISTOLICA],
                PercentilesDeCrecimiento::DIASTOLICA => $filas[PercentilesDeCrecimiento::DIASTOLICA],
                PercentilesDeCrecimiento::PULSO => $filas[PercentilesDeCrecimiento::PULSO],
            ],
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function filas(string $tabla, string $regMedico): array
    {
        $propias = DB::table($tabla)->where('reg_medico', $regMedico)->orderBy('edad_ini_dia')->get();

        if ($propias->isEmpty()) {
            $propias = DB::table($tabla)->whereNull('reg_medico')->orderBy('edad_ini_dia')->get();
        }

        return $propias->map(fn ($fila) => (array) $fila)->all();
    }
}
