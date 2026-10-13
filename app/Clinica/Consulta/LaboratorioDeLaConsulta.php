<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Consulta;
use App\Models\ExamenObs;
use Illuminate\Support\Collection;

/**
 * **La orden de exámenes de laboratorio de una consulta** (WEB-5.4, pestaña *Laboratorio* de las *Solicitudes*, ítem 11 del panel).
 *
 * ## Qué guarda cada tabla (verificado en la fuente, 2026-10-12)
 *
 * El escritorio tiene **dos** pestañas para lo mismo —«Exámenes de laboratorio» (`tabpage_7`) y «Rutinas de exámenes» (`tabpage_8`)—
 * que escriben **las mismas filas**: una pestaña elige los exámenes de a uno y la otra pasa todos los de un tipo (una *rutina*,
 * ver `CatalogoParaPedirExamenes`). La web las junta en una sola pestaña, *Laboratorio*, porque el dato es uno:
 *
 *  - **`examen_paciente`** — un examen pedido: `nrohistoria` + `nroconsulta` + `codeexamen` (la clave). El *Agregar/Pasar* del escritorio
 *    es `INSERT … (nrohistoria, nroconsulta, codeexamen)` (`w_consulta_principal.srw:16093` y `:16201`; la rutina, `:16630-16640`) y el *Eliminar* un
 *    `DELETE` por las tres claves (`:16152` y `:16578`). **`resultado` y `vinculante` no las escribe nadie**: vacías en las
 *    318.490 filas de la base de desarrollo. Esta pestaña no las edita ni las toca.
 *  - **`examen_obs.observacion`** — el campo **«Resumen clínico»** de la solicitud (`d_observecion_examenes.srd`, el `dw_14`; en la hoja
 *    impresa sale como «IDX. / Resumen Clinico»): un texto por consulta. El escritorio crea la fila vacía cada vez que agrega un examen
 *    (`:16103`, `:16211` y `:16646`).
 *  - El catálogo es `examenes` sin el tipo `002` y los nombres de la hoja salen de `examenes` / `tipos_examenes`.
 *
 * Los exámenes de la consulta los lee `ExamenesDeLaConsulta` (WEB-4.7: la sección *Exámenes* ve lo mismo que arma esta pestaña, sin
 * duplicar la lectura); esta clase le suma el orden de la orden —por tipo y por nombre, que es como se imprime— y la regla de «qué es
 * una orden» (`QueEsUnDocumento::laboratorio`).
 *
 * Sólo lectura: la escritura es `GuardarOrdenDeLaboratorio` / `EliminarOrdenDeLaboratorio`.
 */
final class LaboratorioDeLaConsulta
{
    /** `examen_obs.observacion` es `long varchar` (32.766 en el DataWindow): la web admite 20.000, como el informe. */
    public const LARGO_OBSERVACION = 20000;

    public function __construct(private ExamenesDeLaConsulta $examenes)
    {
    }

    /**
     * Los exámenes pedidos, **por tipo y por nombre** (el orden de la hoja). Incluye las filas cuyo código no está en el catálogo (se
     * muestran con su código y sin tipo) y las que tienen resultado: nada se esconde.
     *
     * @return Collection<int,object{id:int, codigo:string, nombre:string, tipo:?string, codetipo:?string, resultado:?string, vinculante:?string}>
     */
    public function filas(Consulta $consulta): Collection
    {
        return $this->examenes->deLaConsulta((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta)
            ->sortBy(fn (object $fila) => ($fila->tipo === null ? '1' : '0')
                . BusquedaDePacientes::normalizar((string) $fila->tipo) . '|'
                . BusquedaDePacientes::normalizar((string) $fila->nombre) . '|' . str_pad((string) $fila->codigo, 8, '0', STR_PAD_LEFT))
            ->values();
    }

    /** La observación (el «Resumen clínico»), o `null` si no hay texto. */
    public function observacion(Consulta $consulta): ?string
    {
        return $this->examenes->observacion((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta);
    }

    /** La fila de `examen_obs` de la consulta (con o sin texto), o `null`. */
    public function filaDeObservacion(Consulta $consulta): ?ExamenObs
    {
        return ExamenObs::where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', (int) $consulta->numhistoria)
            ->where('numconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /** ¿La consulta tiene una orden de verdad? Es `QueEsUnDocumento::laboratorio` aplicado a lo que ya se leyó. */
    public function esReal(Collection $filas, ?string $observacion): bool
    {
        return QueEsUnDocumento::laboratorio($filas->count(), $observacion);
    }
}
