<?php

namespace App\Clinica\Vacunas;

use Illuminate\Support\Facades\DB;

/**
 * **Lee lo que necesita la pantalla de vacunas del niño** (WEB-7.4.4a, O-68/O-69): el catálogo de vacunas, los tipos de
 * vacuna y las dosis **del consultorio** (`reg_medico`) y las **filas del niño** en `vacuna_paciente` —lo aplicado y lo
 * programado, que desde O-68 son la pantalla principal—. Nunca lee el catálogo inicial: si el médico no tiene vacunas, la
 * pantalla lo dice y ofrece cargarlas (la copia es `CopiarVacunasIniciales`).
 *
 * `aplicadas` se llama así por historia (el calendario nació para cruzar lo aplicado) pero trae **todas** las filas del
 * niño, incluidas las que el escritorio dejó planeadas (`ejecutada = 'N'`) y las que programa la web. Renombrarla tocaría
 * a todos sus consumidores y no cambia el dato: queda señalado.
 */
class VacunasDelPaciente
{
    /**
     * @return array{
     *     vacunas:array<int,object>,
     *     dosis:array<string,array<int,object>>,
     *     aplicadas:array<int,object>,
     *     tipos:array<string,string>
     * }
     */
    public function de(string $regMedico, ?int $numhistoria): array
    {
        $vacunas = DB::table('vacunas')->where('reg_medico', $regMedico)->orderBy('edad_inicial_meses')->orderBy('vacuna')->orderBy('id')->get()
            ->unique('codi_vacuna')->values()->all();

        $dosis = DB::table('vacunas_dosis')->where('reg_medico', $regMedico)->orderBy('dosis')->get()
            ->groupBy('codi_vacuna')->map(fn ($filas) => $filas->all())->all();

        $aplicadas = $numhistoria === null ? [] : DB::table('vacuna_paciente')
            ->where('reg_medico', $regMedico)->where('historia', $numhistoria)->orderBy('fecha_ejecutada')->get()->all();

        // El nombre del «Tipo de vacuna» de la grilla del escritorio (`d_vacunas_consulta_dos.srd:29-58`): `vacuna_paciente`
        // guarda el código y el nombre está en `tipo_vacuna`.
        $tipos = DB::table('tipo_vacuna')->where('reg_medico', $regMedico)->orderBy('id')->get()
            ->mapWithKeys(fn ($tipo) => [strtoupper(trim((string) $tipo->tipo_vacu)) => trim((string) $tipo->vacuna)])
            ->filter()->all();

        return ['vacunas' => $vacunas, 'dosis' => $dosis, 'aplicadas' => $aplicadas, 'tipos' => $tipos];
    }
}
