<?php

namespace App\Clinica\Consulta\Traumatologia;

/**
 * **El mapa de la plantilla ortopédica** (Traumatología, 2026-11-07): las columnas de `ortopedia` con el rótulo de la
 * hoja del escritorio (`d_ortopedia.srd`) y el de la ventana (`w_consulta_principal.srw`, `tabpage_6`).
 *
 * Las casillas son `char(1)` con **`1`/`0`** (el `IF ls_bot="1"` del fuente), no `S`/`N`: el mapa lo dice con
 * `tipo = 'marca'` y la escritura traduce la casilla a `1`/`0`.
 */
final class MapaDeLaOrtopedia
{
    public const SECCION = 'ortopedia';

    public const TABLA = 'ortopedia';

    /** El rótulo de la sección en el árbol de la consulta. */
    public const TITULO = 'Ortopedia';

    /**
     * Los campos, en el orden de la hoja.
     *
     * @return array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int, opciones?:array<string,string>}>
     */
    public static function campos(): array
    {
        return [
            // Calzados ortopédicos.
            ['clave' => 'botas', 'etiqueta' => 'Botas ortopédicas', 'columna' => 'botas', 'tipo' => 'marca'],
            ['clave' => 'zapatos', 'etiqueta' => 'Calzado colegial', 'columna' => 'zapatos', 'tipo' => 'marca'],
            ['clave' => 'normal', 'etiqueta' => 'Calzado normal', 'columna' => 'normal', 'tipo' => 'marca'],
            ['clave' => 'soporte', 'etiqueta' => 'Soporte plantar', 'columna' => 'soporte', 'tipo' => 'texto', 'max' => 6],
            ['clave' => 'thomas', 'etiqueta' => 'Contrafuerte', 'columna' => 'thomas', 'tipo' => 'texto', 'max' => 8],
            ['clave' => 'plantilla', 'etiqueta' => 'Plantilla', 'columna' => 'plantilla', 'tipo' => 'marca'],
            // Tacón y suela.
            ['clave' => 'tacon_int', 'etiqueta' => 'Tacón (en tacón)', 'columna' => 'tacon_int', 'tipo' => 'texto', 'max' => 5],
            ['clave' => 'tacon_ext', 'etiqueta' => 'Tacón (en suela)', 'columna' => 'tacon_ext', 'tipo' => 'texto', 'max' => 5],
            ['clave' => 'suela_int', 'etiqueta' => 'Cuña interna', 'columna' => 'suela_int', 'tipo' => 'texto', 'max' => 5],
            ['clave' => 'suela_ext', 'etiqueta' => 'Cuña externa', 'columna' => 'suela_ext', 'tipo' => 'texto', 'max' => 5],
            // Bordes y suela.
            ['clave' => 'borde_int_rect', 'etiqueta' => 'Borde interno recto', 'columna' => 'borde_int_rect', 'tipo' => 'marca'],
            ['clave' => 'borde_int_abdu', 'etiqueta' => 'Borde interno invertido', 'columna' => 'borde_int_abdu', 'tipo' => 'marca'],
            ['clave' => 'suela_normal', 'etiqueta' => 'Suela normal', 'columna' => 'suela_normal', 'tipo' => 'marca'],
            ['clave' => 'suela_antirres', 'etiqueta' => 'Suela antirresbalante', 'columna' => 'suela_antirres', 'tipo' => 'marca'],
            // Aparatos y cierre.
            ['clave' => 'aparatos', 'etiqueta' => 'Aparatos', 'columna' => 'aparatos', 'tipo' => 'texto', 'max' => 20],
            // `aae` y `api` son los desplegables del aparato; sus opciones no están en el fuente y no se exponen.
            ['clave' => 'observacion', 'etiqueta' => 'Observación', 'columna' => 'observacion', 'tipo' => 'parrafo'],
        ];
    }

    /** Las columnas de la tabla que escriben estos campos. */
    public static function columnas(): array
    {
        return array_column(self::campos(), 'columna');
    }
}
