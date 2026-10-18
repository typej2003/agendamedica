<?php

namespace App\Clinica\Vacunas;

use Illuminate\Support\Facades\DB;

/**
 * **Lee lo que necesita el calendario de vacunas** (WEB-7.4.4a): el catálogo de vacunas y las dosis **del consultorio** (`reg_medico`) y lo
 * aplicado al niño (`vacuna_paciente`, por su historia). Nunca lee el catálogo inicial: si el médico no tiene vacunas, el calendario dice que
 * hay que cargarlas (la copia es `CopiarVacunasIniciales`).
 */
class VacunasDelPaciente
{
    /**
     * @return array{vacunas:array<int,object>, dosis:array<string,array<int,object>>, aplicadas:array<int,object>}
     */
    public function de(string $regMedico, ?int $numhistoria): array
    {
        $vacunas = DB::table('vacunas')->where('reg_medico', $regMedico)->orderBy('edad_inicial_meses')->orderBy('vacuna')->orderBy('id')->get()
            ->unique('codi_vacuna')->values()->all();

        $dosis = DB::table('vacunas_dosis')->where('reg_medico', $regMedico)->orderBy('dosis')->get()
            ->groupBy('codi_vacuna')->map(fn ($filas) => $filas->all())->all();

        $aplicadas = $numhistoria === null ? [] : DB::table('vacuna_paciente')
            ->where('reg_medico', $regMedico)->where('historia', $numhistoria)->orderBy('fecha_ejecutada')->get()->all();

        return ['vacunas' => $vacunas, 'dosis' => $dosis, 'aplicadas' => $aplicadas];
    }
}
