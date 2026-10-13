<?php

namespace App\Actions\Consulta;

use App\Models\Diagnostico;
use App\Models\Medico;
use InvalidArgumentException;

/**
 * **Alta de un diagnóstico en el catálogo del médico** (`diagnosticos`, WEB-4.5, ítem 8 del panel).
 *
 * Es lo que hace el botón *Agregar* de la pestaña de diagnósticos del escritorio: abre
 * `w_nuevo_diagnostico` (`w_consulta_principal.srw:11759-11760`) y esa ventana guarda con
 * `dw_1.update()` sobre el DataWindow `d_a_diagnostico.srd`, que inserta `codediagnostico` y
 * `descripcion` en `diagnosticos` (`sle_2` → `dw_1.object.descripcion` en `w_nuevo_diagnostico.srw:547`
 * y `ue_guardar_2` en `:98-113`).
 *
 * **El código lo da el servidor**, como el de motivos (PLAN-WEB.md §3.15: "los catálogos clínicos se
 * amplían desde el buscador … con el correlativo del servidor"), y es exactamente lo que la ventana del
 * legado hace al abrirse: ordena el catálogo por código, toma el mayor, le suma uno y lo deja en el campo
 * (`w_nuevo_diagnostico.srw:122-137` en `ue_nuevo` y `:234-248` en su `open`). El campo del legado es
 * editable, pero la web **no le pide el código al médico**: es el correlativo del consultorio y ofrecerlo
 * como campo obligatorio sería pedirle un dato que no tiene por qué conocer. La descripción se guarda en
 * mayúsculas, como en los dos catálogos.
 *
 * El catálogo es del **médico** y el código del legado es único por consultorio, así que el correlativo se
 * mide sobre **todos los registros del tenant** —no sólo el del contexto— y, si ya hay uno con la misma
 * descripción en cualquiera de ellos, se devuelve **el existente** sin duplicar (el catálogo del legado
 * trae repetidos y no se agrega uno más). Las dos reglas que comparte con `AgregarMotivoAlCatalogo`
 * (mayúsculas y correlativo de cuatro dígitos) viven en `ReglasDelCatalogo`.
 *
 * **Online-only por decisión de producto** (la misma del móvil y de WEB-4.2): crear un diagnóstico es poco
 * frecuente —al inicio se cargan varios y después casi siempre se reusan—, así que no viaja por la cola de
 * sync: la superficie espera la respuesta y la sección lo dice en pantalla.
 */
final class AgregarDiagnosticoAlCatalogo
{
    /** El largo del `descripcion` del catálogo del legado (`diagnosticos.descripcion` es `varchar(50)`). */
    public const LARGO_MAXIMO = 50;

    /**
     * @param  Medico  $medico  el médico **de la cuenta** que está creando el diagnóstico (de ahí sale el
     *                          `reg_medico` principal con el que se guarda la fila)
     * @param  array<int,string>  $registrosMedicos  los registros del tenant, para no duplicar uno que ya
     *                                               existe en cualquiera de ellos
     * @return array{0: Diagnostico, 1: bool} el diagnóstico y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si la descripción está vacía o es muy larga, o si el catálogo está lleno
     */
    public function ejecutar(Medico $medico, array $registrosMedicos, string $descripcion): array
    {
        $descripcion = ReglasDelCatalogo::descripcion($descripcion, self::LARGO_MAXIMO, 'diagnóstico');

        $catalogo = Diagnostico::whereIn('reg_medico', $registrosMedicos)->get();

        $existente = $catalogo->first(fn (Diagnostico $d) => mb_strtoupper(trim((string) $d->descripcion)) === $descripcion);
        if ($existente) {
            return [$existente, false];
        }

        $diagnostico = Diagnostico::create([
            'reg_medico' => $medico->regMedicoPrincipal(),
            'codediagnostico' => ReglasDelCatalogo::siguienteCodigo($catalogo->pluck('codediagnostico'), 'diagnósticos'),
            'descripcion' => $descripcion,
        ]);

        return [$diagnostico, true];
    }
}
