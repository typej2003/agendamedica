<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\RadiologiaObs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **La solicitud de estudios de una consulta** (WEB-5.4, pestaña *Estudios* de las *Solicitudes*, ítem 11 del panel): los estudios de
 * imágenes que se le piden al paciente (radiografías, ecografías, tomografías…).
 *
 * ## Qué guarda cada tabla (verificado en la fuente, 2026-10-12)
 *
 * La pestaña «Solicitud de estudios» del escritorio (`w_consulta_principal.srw:16779-17300`, `tabpage_9`) trabaja con:
 *
 *  - **`radiologia_paciente`** — un estudio pedido: `nrohistoria` + `nroconsulta` + `coderadio` (la clave), `nroopcion` y `orden`. Su
 *    `coderadio` es el **`codeexamen` de un examen del catálogo `examenes` de tipo `002`** («ESTUDIO DE IMAGENES»), no un código de la
 *    tabla `radiologias` (que nadie nombra; ver `CatalogoParaPedirExamenes`): el `DataWindow` de la lista cruza
 *    `radiologia_paciente.coderadio = examenes.codeexamen` y exige `codetipo = '002'` (`d_radiologia_dos.srd`). El *Pasar* es
 *    `INSERT … (nrohistoria, nroconsulta, coderadio, nroopcion, orden) VALUES (…, 1, <cantidad de filas del catálogo>)` (`:17231-17240`) y
 *    el *Eliminar* un `DELETE` por las tres claves (`:17180`). El botón *Agregar* abre `w_nuevo_radiologia`, que sólo da de alta en `radiologias`
 *    (la tabla que nadie lee), por eso la web no lo replica: un estudio nuevo se crea en el catálogo de exámenes con el tipo `002`.
 *  - **`nroopcion`** es **siempre 1** (está escrito a mano en los dos `INSERT`, `:17120` y `:17231`; 4.260 de 4.264 filas): era el número
 *    de la «opción» de una variante del estudio (`radiologias.opciones`, vacía en todo el catálogo) que nunca se usó. La web lo
 *    escribe en 1 y no lo muestra.
 *  - **`orden`** decide el orden de la hoja (`d_imagenes_solicitadas.srd`: `sort="radiologia_paciente_orden A"`). El escritorio le
 *    pone la **cantidad de filas del catálogo** —el mismo número para todos los estudios que agrega— y sólo 740 de las 4.264 filas
 *    lo tienen, o sea que en la práctica la hoja sale en el orden en que se agregaron. La web lo numera 1, 2, 3… según el orden de la lista.
 *  - **`radiologia_obs.observacion`** — el campo **«Resumen clínico»** de la solicitud (`d_radiologia_obdervecion.srd`): un texto por
 *    consulta, que en la hoja sale como «Resumen clínico:». El escritorio crea la fila vacía cada vez que agrega un estudio.
 *
 * Sólo lectura: la escritura es `GuardarSolicitudDeEstudios` / `EliminarSolicitudDeEstudios`.
 */
final class EstudiosDeLaConsulta
{
    /** `radiologia_obs.observacion` es `long varchar`: la web admite 20.000, como el informe. */
    public const LARGO_OBSERVACION = 20000;

    /** Lo que el escritorio escribe siempre en `nroopcion`. */
    public const OPCION = 1;

    /**
     * Los estudios pedidos, **en el orden de la lista**: `orden` (los que no lo tienen, al final) y después el de creación. Un estudio cuyo
     * código no esté en el catálogo se muestra con su código.
     *
     * @return Collection<int,object{id:int, codigo:string, nombre:string, orden:?int}>
     */
    public function filas(Consulta $consulta): Collection
    {
        $regMedico = (string) $consulta->reg_medico;

        return DB::table('radiologia_paciente as rp')
            ->leftJoin('examenes as e', function ($join) use ($regMedico) {
                $join->on('e.codeexamen', '=', 'rp.coderadio')->where('e.reg_medico', '=', $regMedico);
            })
            ->where('rp.reg_medico', $regMedico)
            ->where('rp.nrohistoria', (int) $consulta->numhistoria)
            ->where('rp.nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('rp.id')
            ->get(['rp.id', 'rp.coderadio', 'rp.orden', 'e.examen'])
            ->map(function (object $fila) {
                $codigo = trim((string) $fila->coderadio);
                $nombre = TextoDeLaConsulta::limpio($fila->examen);

                return (object) [
                    'id' => (int) $fila->id,
                    'codigo' => $codigo,
                    'nombre' => $nombre !== null ? (string) preg_replace('/\s+/u', ' ', $nombre) : $codigo,
                    'orden' => $fila->orden === null ? null : (int) $fila->orden,
                ];
            })
            ->sortBy(fn (object $fila) => str_pad((string) ($fila->orden ?? 999999), 7, '0', STR_PAD_LEFT) . '|' . str_pad((string) $fila->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /** La observación (el «Resumen clínico»), o `null` si no hay texto. */
    public function observacion(Consulta $consulta): ?string
    {
        $fila = $this->filaDeObservacion($consulta);

        return $fila === null ? null : TextoDeLaConsulta::limpio($fila->observacion);
    }

    /** La fila de `radiologia_obs` de la consulta (con o sin texto), o `null`. */
    public function filaDeObservacion(Consulta $consulta): ?RadiologiaObs
    {
        return RadiologiaObs::where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', (int) $consulta->numhistoria)
            ->where('numconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /** ¿La consulta tiene una solicitud de verdad? Es `QueEsUnDocumento::estudios` aplicado a lo que ya se leyó. */
    public function esReal(Collection $filas, ?string $observacion): bool
    {
        return QueEsUnDocumento::estudios($filas->count(), $observacion);
    }
}
