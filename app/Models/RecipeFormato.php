<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Formato de impresión del récipe de un médico (ver la migración `create_recipe_formatos_table`).
 *
 * Los dominios de cada opción se declaran acá y no en el cliente: la validación del controller y los
 * defaults del Resource leen de las mismas constantes.
 */
class RecipeFormato extends Model
{
    protected $table = 'recipe_formatos';

    protected $fillable = ['reg_medico', 'elementos', 'color_linea', 'firma', 'sello', 'sello_posicion'];

    protected $casts = ['elementos' => 'array'];

    /** Bloques del membrete que se configuran por separado — las pestañas del legado. */
    public const ELEMENTOS = ['logo', 'medico', 'especialidad', 'rif', 'pie'];

    /** Elementos de texto: el logo no tiene fuente ni estilo. */
    public const ELEMENTOS_TEXTO = ['medico', 'especialidad', 'rif', 'pie'];

    public const ALINEACIONES = ['izquierda', 'centro', 'derecha'];

    /**
     * Fuentes libres que el cliente incluye en el PDF, equivalentes a las de Windows del legado
     * (`recipe.ini` guarda nombres como "COMIC SANS MS" o "VERDANA"; el mapeo está en
     * PENDIENTES-POWERBUILDER.md). Se guarda el id, no el nombre de Windows.
     */
    public const FUENTES = ['arimo', 'tinos', 'cousine', 'comic_neue', 'dejavu_sans'];

    public const TAMANOS_LOGO = ['chico', 'mediano', 'grande'];

    /** Mismos colores que ofrece el combo "Color de la Línea" del legado. */
    public const COLORES_LINEA = [
        'negro', 'blanco', 'rojo', 'marron', 'fucsia', 'verde', 'gris', 'amarillo', 'azul',
    ];

    public const POSICIONES_SELLO = ['izquierda', 'centro', 'derecha'];

    /** Lo que ve un médico que nunca configuró nada: el aspecto por defecto del legado. */
    public static function elementosPorDefecto(): array
    {
        $texto = fn (bool $negrita) => [
            'alineacion' => 'centro',
            'fuente' => 'arimo',
            'negrita' => $negrita,
            'italica' => false,
            'subrayado' => false,
            'borde' => false,
            'visible' => true,
        ];

        return [
            'logo' => ['alineacion' => 'izquierda', 'tamano' => 'mediano', 'borde' => false, 'visible' => true],
            'medico' => $texto(false),
            'especialidad' => $texto(true),
            'rif' => $texto(true),
            'pie' => $texto(false),
        ];
    }

    /**
     * Los elementos guardados completados con los defaults: una fila vieja a la que le falta una
     * opción agregada después sigue saliendo completa.
     */
    public function elementosResueltos(): array
    {
        return array_replace_recursive(self::elementosPorDefecto(), $this->elementos ?? []);
    }
}
