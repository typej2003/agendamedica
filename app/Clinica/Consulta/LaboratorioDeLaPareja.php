<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Consulta;
use App\Models\ExamenParejaDatos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **La orden de laboratorio de la pareja** (WEB-11.21): el botón «Pareja» de la pestaña Laboratorio del escritorio abre el diálogo
 * «Examen de la Pareja» (`w_consulta_examen_pareja.srw`) para pedirle exámenes a la pareja del paciente cuando la enfermedad es
 * contagiosa. La orden sale **a nombre de la pareja** y **se guarda**: al volver a abrirla se ve como se dejó.
 *
 * Qué lee y de dónde:
 *
 *  - **`examen_pareja`** (legada, `nrohistoria` + `nroconsulta` + `codeexamen`): los exámenes pedidos; el escritorio copia en cada fila el
 *    `nombre` y la `cedula` de la pareja.
 *  - **`examen_pareja_datos`** (nueva de AppDDR, una por consulta): la C.I., el nombre y el **resumen clínico**. El escritorio comparte el
 *    resumen con el de la paciente (`examen_obs`); la web no mezcla los dos.
 *
 * Sólo lectura: la escritura es `GuardarOrdenDeLaPareja` / `EliminarOrdenDeLaPareja`.
 */
final class LaboratorioDeLaPareja
{
    /** Como el resumen de la orden de la paciente (`LaboratorioDeLaConsulta::LARGO_OBSERVACION`). */
    public const LARGO_OBSERVACION = LaboratorioDeLaConsulta::LARGO_OBSERVACION;

    /** `examen_pareja.nombre` y `.cedula`: el esquema del legado. */
    public const LARGO_NOMBRE = 100;

    public const LARGO_CEDULA = 20;

    /**
     * Los exámenes pedidos a la pareja, **por tipo y por nombre** (el orden de la hoja), con el mismo formato de `LaboratorioDeLaConsulta::filas`.
     *
     * @return Collection<int,object{id:int, codigo:string, nombre:string, tipo:?string, codetipo:?string}>
     */
    public function filas(Consulta $consulta): Collection
    {
        $regMedico = (string) $consulta->reg_medico;

        return DB::table('examen_pareja as ep')
            ->leftJoin('examenes as e', function ($join) use ($regMedico) {
                $join->on('e.codeexamen', '=', 'ep.codeexamen')->where('e.reg_medico', '=', $regMedico);
            })
            ->leftJoin('tipos_examenes as t', function ($join) use ($regMedico) {
                $join->on('t.codetipo', '=', 'e.codetipo')->where('t.reg_medico', '=', $regMedico);
            })
            ->where('ep.reg_medico', $regMedico)
            ->where('ep.nrohistoria', (int) $consulta->numhistoria)
            ->where('ep.nroconsulta', (int) $consulta->nroconsulta)
            ->get(['ep.id', 'ep.codeexamen', 'e.examen', 'e.codetipo', 't.tipo'])
            ->map(function ($fila) {
                $codigo = trim((string) $fila->codeexamen);

                return (object) [
                    'id' => (int) $fila->id,
                    'codigo' => $codigo,
                    'nombre' => TextoDeLaConsulta::limpio($fila->examen) ?? $codigo,
                    'tipo' => TextoDeLaConsulta::limpio($fila->tipo),
                    'codetipo' => TextoDeLaConsulta::limpio($fila->codetipo),
                ];
            })
            ->sortBy(fn (object $fila) => ($fila->tipo === null ? '1' : '0')
                . BusquedaDePacientes::normalizar((string) $fila->tipo) . '|'
                . BusquedaDePacientes::normalizar((string) $fila->nombre) . '|' . str_pad((string) $fila->codigo, 8, '0', STR_PAD_LEFT))
            ->values();
    }

    /** La fila de los datos de la pareja de la consulta, o `null`. */
    public function filaDeDatos(Consulta $consulta): ?ExamenParejaDatos
    {
        return ExamenParejaDatos::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La C.I., el nombre y el resumen de la pareja. Si no hay fila de datos pero sí exámenes del escritorio (que copia el nombre y la
     * C.I. en cada uno), se toman de ahí.
     *
     * @return array{cedula:?string, nombre:?string, observacion:?string}
     */
    public function datos(Consulta $consulta): array
    {
        $fila = $this->filaDeDatos($consulta);

        if ($fila !== null) {
            return [
                'cedula' => TextoDeLaConsulta::limpio($fila->cedula),
                'nombre' => TextoDeLaConsulta::limpio($fila->nombre),
                'observacion' => TextoDeLaConsulta::limpio($fila->observacion),
            ];
        }

        $delEscritorio = DB::table('examen_pareja')
            ->where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first(['cedula', 'nombre']);

        return [
            'cedula' => TextoDeLaConsulta::limpio($delEscritorio->cedula ?? null),
            'nombre' => TextoDeLaConsulta::limpio($delEscritorio->nombre ?? null),
            'observacion' => null,
        ];
    }

    /**
     * ¿La consulta tiene una orden de la pareja de verdad? Hay algún examen o, al menos, los datos de la pareja o su resumen.
     *
     * @param  array{cedula:?string, nombre:?string, observacion:?string}  $datos
     */
    public function esReal(Collection $filas, array $datos): bool
    {
        return $filas->count() > 0 || $datos['cedula'] !== null || $datos['nombre'] !== null || $datos['observacion'] !== null;
    }
}
