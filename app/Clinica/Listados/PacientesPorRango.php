<?php

namespace App\Clinica\Listados;

use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El listado de pacientes por rango** (WEB-6.8): lo que el escritorio muestra en `w_reprotes_pacientes.srw`
 * (*Indicadores pacientes*) con sus ocho DataWindows `d_reporte-pacientes_*`, que son **una sola tabla**
 * (`pacientes`, sin `JOIN`) elegida por la combinación de filtros. La web resuelve esa combinatoria con los
 * filtros de `FiltroDePacientesPorRango` y esta lectura.
 *
 * **Las diferencias con el escritorio, y por qué:**
 *
 *  1. **`pacientes.numhistoria` no existe en el esquema de AppDDR.** El número de historia vive en
 *     `medico_pacientes.numhistoria`, el pivote del médico (de ahí lo lee toda la web). Ese pivote es además lo
 *     que **acota el listado al `reg_medico` del contexto**: el legado no filtraba por médico porque cada
 *     instalación tenía su propia base.
 *  2. **El orden** es el del `sort="numhistoria A"` del DataWindow, pero el número es un texto (`'00012'`) y
 *     ordenarlo como texto pondría el `'9'` después del `'10'`: se ordena **como número**, con los pacientes
 *     que todavía no tienen historia al final y el `id` de desempate. Se hace en memoria porque la cuenta
 *     numérica de un texto es distinta en SQLite y en MySQL, y el listado es de un solo consultorio.
 *  3. **El estado civil se compara con `pacientes.codeestado`**, no con `codesegemp` como lo hacen dos de los
 *     ocho DataWindows del legado (ver `FiltroDePacientesPorRango`).
 *
 * **La edad sólo filtra cuando el médico la pone** (O-14, 2026-10-17): con el rango **abierto** —el de la
 * pantalla, 1 a 110— no se agrega la condición de `fnacimiento`, así que un paciente **sin fecha de
 * nacimiento** aparece igual (el alta rápida de «Nueva cita» no pide ese dato). El `NULL` del legado y el
 * listado de embarazos siguen filtrando cuando la edad sí se pidió.
 *
 * Sólo lectura: no crea ni modifica ninguna fila.
 */
final class PacientesPorRango
{
    /**
     * Los pacientes del médico del contexto que cumplen los filtros.
     *
     * @return Collection<int,object> filas con `paciente_id`, `nombres`, `apellidos`, `nac`, `cedula`,
     *         `fnacimiento`, `codeestado`, `sexo`, `fingreso`, `codesegemp` y `numhistoria`
     */
    public function filas(string $regMedico, FiltroDePacientesPorRango $filtro): Collection
    {
        $ingreso = $filtro->rangoDeIngreso();

        $consulta = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $regMedico)
            ->whereBetween('pacientes.fingreso', [$ingreso->desde(), $ingreso->hasta()]);

        // La edad abierta no filtra (O-14): si se pidió un rango, se traduce a fechas de nacimiento.
        if ($filtro->filtraPorEdad()) {
            $nacimiento = $filtro->rangoDeNacimiento();
            $consulta->whereBetween('pacientes.fnacimiento', [$nacimiento->desde(), $nacimiento->hasta()]);
        }

        if ($filtro->sexo() !== null) {
            $consulta->where('pacientes.sexo', $filtro->sexo());
        }

        if ($filtro->estadoCivil() !== null) {
            $consulta->where('pacientes.codeestado', $filtro->estadoCivil());
        }

        if ($filtro->codigoDeProcedencia() !== null) {
            $consulta->where('pacientes.codesegemp', $filtro->codigoDeProcedencia());
        }

        return $consulta
            ->distinct()
            ->get([
                'pacientes.id as paciente_id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.nac',
                'pacientes.cedula',
                'pacientes.fnacimiento',
                'pacientes.codeestado',
                'pacientes.sexo',
                'pacientes.fingreso',
                'pacientes.codesegemp',
                'medico_pacientes.numhistoria',
            ])
            ->sortBy(function (object $fila) {
                $numero = CamposDePaciente::historiaNumerica($fila->numhistoria);

                // Primero los que tienen número, por número; después los que no, y el `id` desempata.
                return [$numero === null ? 1 : 0, $numero ?? 0, (int) $fila->paciente_id];
            })
            ->values();
    }
}
