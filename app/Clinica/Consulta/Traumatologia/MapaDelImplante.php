<?php

namespace App\Clinica\Consulta\Traumatologia;

/**
 * **El mapa de la solicitud de implante** (Traumatología, T3, 2026-11-10): las columnas de `implante` con el rótulo de
 * la ventana (`d_solicitud_implante_cadera.srd` / `d_solicitud_implante.srd`, `tabpage_23` de la consulta) y de la hoja
 * («SOLICITUD IMPLANTE»).
 *
 * Es **una fila por consulta** (`historia` + `consulta`): el escritorio elige la hoja al imprimir —cadera, rodilla o la
 * de **texto libre** (`puro_texto`, el DataWindow `_pt`)—, pero el dato es el mismo. Las casillas son `char(1)` con
 * **`1`/`0`**, como en la plantilla ortopédica.
 */
final class MapaDelImplante
{
    public const SECCION = 'implante';

    public const TABLA = 'implante';

    /** El rótulo de la pestaña en *Solicitudes*. */
    public const TITULO = 'Implante';

    /**
     * Los grupos de la ventana, con sus campos.
     *
     * @return array<string,array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int}>>
     */
    public static function grupos(): array
    {
        return [
            'Datos de la intervención' => [
                ['clave' => 'fecha_reporte', 'etiqueta' => 'Fecha', 'columna' => 'fecha_reporte', 'tipo' => 'fecha'],
                ['clave' => 'fecha_opera', 'etiqueta' => 'Fecha de la intervención', 'columna' => 'fecha_opera', 'tipo' => 'fecha'],
                ['clave' => 'sistema', 'etiqueta' => 'Sistema', 'columna' => 'sistema', 'tipo' => 'texto', 'max' => 100],
                ['clave' => 'marca', 'etiqueta' => 'Marca', 'columna' => 'marca', 'tipo' => 'texto', 'max' => 20],
                ['clave' => 'tipo', 'etiqueta' => 'Tipo', 'columna' => 'tipo', 'tipo' => 'texto', 'max' => 20],
            ],
            'Integrada por' => [
                ['clave' => 'estabilizada', 'etiqueta' => 'Estabilizada', 'columna' => 'estabilizada', 'tipo' => 'marca'],
                ['clave' => 'componente_femoral', 'etiqueta' => 'Componente femoral', 'columna' => 'componente_femoral', 'tipo' => 'marca'],
                ['clave' => 'componente_tibial', 'etiqueta' => 'Componente tibial', 'columna' => 'componente_tibial', 'tipo' => 'texto', 'max' => 10],
                ['clave' => 'componente_tibial_it', 'etiqueta' => 'Componente tibial (I.T.)', 'columna' => 'componente_tibial_it', 'tipo' => 'texto', 'max' => 10],
                ['clave' => 'componente_pastelar', 'etiqueta' => 'Componente pastelar', 'columna' => 'componente_pastelar', 'tipo' => 'marca'],
            ],
            'Insumos' => [
                ['clave' => 'reque_metilmeticatrilato', 'etiqueta' => 'Metilmetacrilato', 'columna' => 'reque_metilmeticatrilato', 'tipo' => 'marca'],
                ['clave' => 'reque_metilmeticatrilato_cant', 'etiqueta' => 'Metilmetacrilato (cantidad)', 'columna' => 'reque_metilmeticatrilato_cant', 'tipo' => 'texto', 'max' => 10],
                ['clave' => 'reque_steridrape', 'etiqueta' => 'Steridrape', 'columna' => 'reque_steridrape', 'tipo' => 'marca'],
                ['clave' => 'reque_steridrape_cant', 'etiqueta' => 'Steridrape (cantidad)', 'columna' => 'reque_steridrape_cant', 'tipo' => 'texto', 'max' => 10],
                ['clave' => 'reque_coban_esteril', 'etiqueta' => 'Coban estéril', 'columna' => 'reque_coban_esteril', 'tipo' => 'marca'],
                ['clave' => 'reque_coban_esteril_cant', 'etiqueta' => 'Coban estéril (cantidad)', 'columna' => 'reque_coban_esteril_cant', 'tipo' => 'texto', 'max' => 10],
                ['clave' => 'reque_autosutura', 'etiqueta' => 'Autosutura', 'columna' => 'reque_autosutura', 'tipo' => 'marca'],
                ['clave' => 'reque_autosutura_cant', 'etiqueta' => 'Autosutura (cantidad)', 'columna' => 'reque_autosutura_cant', 'tipo' => 'texto', 'max' => 10],
                ['clave' => 'reque_orthopack', 'etiqueta' => 'Orthopack', 'columna' => 'reque_orthopack', 'tipo' => 'marca'],
                ['clave' => 'reque_orthopack_cant', 'etiqueta' => 'Orthopack (cantidad)', 'columna' => 'reque_orthopack_cant', 'tipo' => 'texto', 'max' => 10],
            ],
            'Diagnóstico y plan' => [
                ['clave' => 'diagnostico', 'etiqueta' => 'Diagnóstico(s)', 'columna' => 'diagnostico', 'tipo' => 'parrafo'],
                ['clave' => 'plan_operatorio', 'etiqueta' => 'Plan operatorio', 'columna' => 'plan_operatorio', 'tipo' => 'parrafo'],
            ],
            'Texto libre' => [
                ['clave' => 'puro_texto', 'etiqueta' => 'Solicitud en texto libre (la hoja «_pt» del escritorio)', 'columna' => 'puro_texto', 'tipo' => 'parrafo'],
            ],
        ];
    }

    /**
     * Los campos, en el orden de los grupos.
     *
     * @return array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int}>
     */
    public static function campos(): array
    {
        return array_merge(...array_values(self::grupos()));
    }

    /** Las columnas de la tabla que escriben estos campos. */
    public static function columnas(): array
    {
        return array_column(self::campos(), 'columna');
    }
}
