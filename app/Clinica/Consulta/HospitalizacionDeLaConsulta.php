<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\Hospitalizacion;

/**
 * **La orden de hospitalización de una consulta** (WEB-5.3b, pestaña *Hospitalización* de las *Solicitudes*, ítem 11 del panel).
 *
 * ## Una por consulta (verificado en la fuente y en el esquema)
 *
 * `hospitalizacion` se llavea por `nrohistoria` + `nroconsulta` (`PRIMARY KEY` en `esquemas-asa/*.sql`) y la pestaña trabaja la fila de
 * esa consulta (`dw_26.retrieve(historia, consulta)`, `w_consulta_principal.srw:3329` y `:4921`): **una por consulta**. Si no hay,
 * la ventana inserta una fila vacía en memoria (`:3331-3334`) y la guarda con el resto de la consulta (`ue_salvar_hosp` ⇒
 * `dw_26.update()`, `:18229`). Son 114.499 de las 119.299 filas de la base de desarrollo: **no son órdenes de nadie**
 * (`QueEsUnDocumento::hospitalizacion`).
 *
 * ## Qué columnas edita la pestaña
 *
 * El DataWindow `d_hopitalizacion_paci` (`datawin_procesos/`) muestra **tres** campos, y son los únicos que se editan:
 *
 *  - **`hospital`** — «Lugar a hospitalizar», texto libre de hasta 60 caracteres (`edit.limit=60`). No es un catálogo: la columna
 *    es un `char(60)` suelto y la fuente no la cruza con ninguna tabla (la web tampoco inventa uno);
 *  - **`diagprev`** — «Diagnóstico» (en la hoja, «Diagnóstico provisional»), texto largo. La fuente **no** lo importa de los
 *    diagnósticos de la consulta (la fila nace vacía); la web, como a la constancia, le ofrece **copiarlos** al campo con un botón,
 *    sin guardar;
 *  - **`dieta`** — «Recomendaciones», texto largo (no es la tabla `dieta_paciente`).
 *
 * `indiprev` y `habitacion` están en la tabla y en la consulta de la hoja, pero **ninguna pantalla las edita ni la hoja las
 * imprime** (están vacías en las 119.299 filas): la web no las muestra y, al guardar, **no las toca**.
 *
 * Es de **sólo lectura**: la escritura son `GuardarHospitalizacionDeLaConsulta` y `EliminarHospitalizacionDeLaConsulta`.
 */
final class HospitalizacionDeLaConsulta
{
    /** `hospitalizacion.hospital` es `char(60)` (`edit.limit=60`). */
    public const LARGO_HOSPITAL = 60;

    /** El tope de los dos textos largos (`diagprev`, `dieta`): el escritorio admite 32.000; la web, 20.000 como el informe. */
    public const LARGO_TEXTO = 20000;

    /** La fila de `hospitalizacion` de la consulta, o `null`. */
    public function fila(Consulta $consulta): ?Hospitalizacion
    {
        return Hospitalizacion::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /** ¿La fila es una orden de verdad o la plantilla vacía del escritorio? */
    public function esReal(?Hospitalizacion $fila): bool
    {
        return $fila !== null && QueEsUnDocumento::hospitalizacion($fila->hospital, $fila->diagprev, $fila->dieta);
    }
}
