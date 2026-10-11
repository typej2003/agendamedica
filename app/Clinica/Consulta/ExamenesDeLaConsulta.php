<?php

namespace App\Clinica\Consulta;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Los exámenes de una consulta** (WEB-4.7, ítem 7 del panel del legado: *Exámenes*).
 *
 * ## Dónde vive el dato y de qué cuelga (verificado en la fuente, 2026-10-11)
 *
 * Son filas de **`examen_paciente`** y **cuelgan de la consulta**: `nrohistoria` + `nroconsulta` +
 * `codeexamen` (migración núcleo `2026_08_25_055351`, línea 1067). El DataWindow que las lista es
 * `d_examenes_listado` —su tabla es `examen_paciente` y su `retrieve` cruza con el catálogo por
 * `examenes.codeexamen = examen_paciente.codeexamen`
 * (`datawin_procesos/d_examenes_listado.srd:10-13`)—, la tira de laboratorio de la consulta: la ventana
 * la declara con el `dw_12` de `tabpage_7` (`w_consulta_principal.srw:16239-16246`), rotulada **"Exámenes
 * de laboratorio"** (`:16235`), y la recarga con `dw_12.retrieve(historia, consulta)` (`:3018-3019`).
 * Su botón *Agregar* escribe la fila con
 * `INSERT INTO "EXAMEN_PACIENTE" ("nrohistoria", "nroconsulta", "codeexamen") VALUES (:historia,
 * :consulta, :codigo_exam)` (`:16201-16207`) y su botón *Eliminar* la saca por esas tres claves
 * (`:16152-16156`). El `retrieve` del DataWindow **excluye `codetipo = '002'`** ("ESTUDIO DE IMAGENES"
 * en `tipos_examenes`): ésa es la lista de laboratorio, y los estudios de imágenes viven en la misma
 * tabla con otro tipo.
 *
 * Tres columnas de la tabla que **no** son lo que el nombre sugiere, y por eso la sección no las inventa:
 *
 *  - **`resultado`** (`varchar(30)`) existe en el esquema y en el DataWindow, pero **está vacía en todos
 *    los datos reales** (0 filas con valor en el dataset de prueba, 318.469 filas): lo que el médico
 *    escribe como resultado de un examen no se guarda ahí.
 *  - **`vinculante`** (`char(1)`) también está vacía (0 filas) y no la escribe ninguna consulta de la
 *    fuente: se lee tal cual, sin interpretarla.
 *  - **`examen_obs`** (`numhistoria` + `numconsulta` + `observacion`, migración línea 1058) es donde sí
 *    hay texto: **13.344 filas con observación** en el dataset («HIV, VDRL», «CALCIO - FOSFORO - ACIDO
 *    URICO.»). La ventana la escribe con `INSERT INTO "examen_obs" ("numhistoria", "numconsulta")` al
 *    agregar un examen (`:16211-16215`) y su DataWindow es `d_observecion_examenes`, el `dw_14` de la
 *    misma pestaña. Es **la observación del conjunto de exámenes de la consulta**, no de cada fila.
 *
 * El **catálogo** es `examenes` (`codeexamen`, `examen`, `codetipo`) y sus tipos, `tipos_examenes`
 * (`codetipo`, `tipo`: LABORATORIO, ESTUDIO DE IMAGENES, ESTUDIOS ESPECIALES, PREOPERATORIOS…). Los dos
 * catálogos los sube el escritorio y la web **sólo los lee** (su ABM es WEB-8.1).
 *
 * ## Qué hace la web con esto
 *
 * Sólo lectura: lista los exámenes de esta consulta con su nombre resuelto por catálogo, su tipo y su
 * resultado (cuando lo tiene), y la observación del conjunto. **No** ordena exámenes nuevos ni los
 * quita: la fuente los agrega desde la pestaña *Solicitudes* y `examen_paciente` se cruza con las
 * solicitudes impresas, que son **F5/WEB-5.4**. Lo que sí trae WEB-4.7 es la **subida de imágenes**
 * (`ImagenesDeLaConsulta`), que es la otra mitad del ítem.
 *
 * Es **sólo lectura**: la escritura de la sección es `App\Actions\Consulta\GuardarImagenDeLaConsulta`.
 */
final class ExamenesDeLaConsulta
{
    /**
     * Los exámenes de una consulta, con el nombre y el tipo resueltos por catálogo.
     *
     * El `LEFT JOIN` es a propósito: una fila cuyo `codeexamen` no esté en el catálogo del médico **existe
     * y se muestra** (pasa de verdad: un examen con código sin catálogo en el dataset de prueba). Se
     * muestra con su código y sin nombre, que es más honesto que esconderlo.
     *
     * @return Collection<int,object>
     */
    public function deLaConsulta(string $regMedico, int $nrohistoria, int $nroconsulta): Collection
    {
        return DB::table('examen_paciente as ep')
            ->leftJoin('examenes as e', function ($join) use ($regMedico) {
                $join->on('e.codeexamen', '=', 'ep.codeexamen')
                    // El catálogo se acota al médico: el legado repite códigos entre consultorios y sin
                    // esto una fila tomaría el nombre del catálogo de otro.
                    ->where('e.reg_medico', '=', $regMedico);
            })
            ->leftJoin('tipos_examenes as t', function ($join) use ($regMedico) {
                $join->on('t.codetipo', '=', 'e.codetipo')
                    ->where('t.reg_medico', '=', $regMedico);
            })
            ->where('ep.reg_medico', $regMedico)
            ->where('ep.nrohistoria', $nrohistoria)
            ->where('ep.nroconsulta', $nroconsulta)
            ->orderBy('ep.codeexamen')
            ->get([
                'ep.id',
                'ep.codeexamen',
                'ep.resultado',
                'ep.vinculante',
                'e.examen',
                'e.codetipo',
                't.tipo',
            ])
            ->map(function ($fila) {
                $codigo = trim((string) $fila->codeexamen);

                return (object) [
                    'id' => (int) $fila->id,
                    'codigo' => $codigo,
                    // Un examen sin nombre en el catálogo se muestra con su código: el escritorio hace lo
                    // mismo (su `dddw` deja el `codeexamen` a la vista cuando no encuentra el nombre).
                    'nombre' => TextoDeLaConsulta::limpio($fila->examen) ?? $codigo,
                    'tipo' => TextoDeLaConsulta::limpio($fila->tipo),
                    'resultado' => TextoDeLaConsulta::limpio($fila->resultado),
                    'vinculante' => TextoDeLaConsulta::limpio($fila->vinculante),
                ];
            });
    }

    /**
     * La **observación del conjunto de exámenes** de la consulta (`examen_obs.observacion`), o `null`.
     *
     * Es una columna de texto libre donde el escritorio deja lo que hay que pedir o lo que se pidió
     * («HIV, VDRL»), no el resultado de cada examen. La escribe al agregar el primer examen de la
     * consulta (`w_consulta_principal.srw:16211-16215`, el `dw_14` de `d_observecion_examenes`).
     */
    public function observacion(string $regMedico, int $numhistoria, int $numconsulta): ?string
    {
        $fila = DB::table('examen_obs')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numhistoria)
            ->where('numconsulta', $numconsulta)
            ->first(['observacion']);

        return $fila === null ? null : TextoDeLaConsulta::limpio($fila->observacion);
    }

    /**
     * ¿El médico tiene este examen en su catálogo? Se usa para distinguir "el examen no tiene nombre en
     * el catálogo" de "el catálogo está vacío", que son dos estados vacíos distintos.
     */
    public function tieneCatalogo(string $regMedico): bool
    {
        return DB::table('examenes')->where('reg_medico', $regMedico)->exists();
    }
}
