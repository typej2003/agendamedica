<?php

namespace App\Actions\Configuracion;

use App\Models\RecipeFormato;
use Illuminate\Validation\Rule;

/**
 * **Las reglas de validación y de normalización del formato de impresión** (`recipe_formatos`), en un solo lugar para
 * las dos superficies que lo escriben: el API del móvil (`RecipeFormatoController`) y la pantalla "Formato de impresión"
 * de la web clínica (WEB-8.2). Ninguna de las dos las repite (PLAN-WEB.md, R3).
 *
 * Los dominios de cada opción (alineaciones, fuentes, colores, tamaños y posiciones del sello) viven en `RecipeFormato`;
 * acá sólo se arman las reglas de Laravel y la limpieza del arreglo `elementos`.
 *
 * ## La imagen: una política, dos grados
 *
 * El API acepta lo que la regla `image` de Laravel acepta (`image|max:2048`) y **no se tocó** para no cambiar el
 * comportamiento del móvil. La web aplica además `ImagenesDelMembrete`, que exige el tipo **real** (JPG, PNG o WEBP) y
 * deja afuera el SVG: el logo, la firma y el sello se sirven desde el disco `public` y un SVG con scripts abierto desde su
 * URL se ejecutaría en el origen de la aplicación.
 */
final class ReglasDelFormato
{
    /** El tope de la firma y del sello (y del logo) en KB, el mismo que usa el API. */
    public const MAXIMO_IMAGEN_KB = 2048;

    /** Las opciones booleanas de un bloque de texto o del logo. */
    public const OPCIONES_BOOLEANAS = ['negrita', 'italica', 'subrayado', 'borde', 'visible'];

    /**
     * Las reglas completas del formato: color de la línea, posición del sello, firma y sello (con sus banderas de
     * "quitar") y las opciones de cada elemento. Es la misma lista que tenía `RecipeFormatoController`.
     *
     * @param  array<int,mixed>|null  $imagen  las reglas de las imágenes si no son las del API (`image|max:2048`)
     * @return array<string,mixed>
     */
    public static function reglas(?array $imagen = null): array
    {
        $reglas = [
            'color_linea' => ['sometimes', Rule::in(RecipeFormato::COLORES_LINEA)],
            'sello_posicion' => ['sometimes', Rule::in(RecipeFormato::POSICIONES_SELLO)],
            'firma' => $imagen ?? 'sometimes|image|max:' . self::MAXIMO_IMAGEN_KB,
            'sello' => $imagen ?? 'sometimes|image|max:' . self::MAXIMO_IMAGEN_KB,
            'firma_eliminar' => 'sometimes|boolean',
            'sello_eliminar' => 'sometimes|boolean',
            'elementos' => 'sometimes|array',
        ];

        return $reglas + self::reglasDeElementos();
    }

    /**
     * Sólo las reglas de `elementos.*`: la alineación, el borde y la visibilidad de todos; la fuente y el estilo de los
     * bloques de texto; el tamaño del logo.
     *
     * @return array<string,mixed>
     */
    public static function reglasDeElementos(): array
    {
        $reglas = [];

        foreach (RecipeFormato::ELEMENTOS as $elemento) {
            $base = "elementos.{$elemento}";
            $reglas["{$base}.alineacion"] = ['sometimes', Rule::in(RecipeFormato::ALINEACIONES)];
            $reglas["{$base}.borde"] = 'sometimes|boolean';
            $reglas["{$base}.visible"] = 'sometimes|boolean';

            if (in_array($elemento, RecipeFormato::ELEMENTOS_TEXTO, true)) {
                $reglas["{$base}.fuente"] = ['sometimes', Rule::in(RecipeFormato::FUENTES)];
                $reglas["{$base}.negrita"] = 'sometimes|boolean';
                $reglas["{$base}.italica"] = 'sometimes|boolean';
                $reglas["{$base}.subrayado"] = 'sometimes|boolean';
            } else {
                $reglas["{$base}.tamano"] = ['sometimes', Rule::in(RecipeFormato::TAMANOS_LOGO)];
            }
        }

        return $reglas;
    }

    /**
     * Deja en `elementos` solo los elementos y opciones que tienen regla. Hace falta a mano: la regla
     * `elementos => array` hace que `validate()` devuelva el arreglo entero, y sin este filtro un elemento u opción
     * inventados terminarían guardados en el JSON.
     *
     * De paso normaliza los booleanos: en multipart llegan como "1"/"0", y guardados como texto el cliente tendría que
     * interpretar `"0"` como falso.
     *
     * @param  array<string,mixed>  $elementos
     * @return array<string,array<string,mixed>>
     */
    public static function soloOpcionesConocidas(array $elementos): array
    {
        $resultado = [];

        foreach (RecipeFormato::ELEMENTOS as $elemento) {
            if (! is_array($elementos[$elemento] ?? null)) {
                continue;
            }

            $permitidas = in_array($elemento, RecipeFormato::ELEMENTOS_TEXTO, true)
                ? ['alineacion', 'fuente', 'negrita', 'italica', 'subrayado', 'borde', 'visible']
                : ['alineacion', 'tamano', 'borde', 'visible'];

            foreach (array_intersect_key($elementos[$elemento], array_flip($permitidas)) as $opcion => $valor) {
                $resultado[$elemento][$opcion] = in_array($opcion, self::OPCIONES_BOOLEANAS, true)
                    ? filter_var($valor, FILTER_VALIDATE_BOOLEAN)
                    : $valor;
            }
        }

        return $resultado;
    }
}
