<?php

namespace App\Actions\Recipes;

use App\Models\Medico;
use App\Models\RecipeFormato;
use InvalidArgumentException;

/**
 * **Guarda cómo se imprime el récipe de un médico** (WEB-5.2): el modo (Rp. + Indicaciones o sólo Indicaciones) y
 * el nombre del medicamento (comercial o genérico), en su fila de `recipe_formatos`.
 *
 * Es **del doctor que firma la hoja y no de quien imprime** (decisión del usuario, 2026-10-20): lo que eligió la
 * última vez la asistente es lo que ve el médico y al revés, para que los dos impriman igual. Por eso el primer
 * parámetro es el **firmante** —el que resuelve `MedicoQueFirma` desde la consulta o la cita— y no el médico del
 * contexto: en la secretaría de un centro el médico del contexto es la fila del **centro**, y las opciones
 * terminarían en la fila del centro, que es la del membrete y el estilo, no la de la firma.
 *
 * Reglas que se cuidan acá porque la tabla también la lee y la escribe el API del móvil:
 *
 *  - Se guarda bajo el **registro del formato del firmante** (`Medico::registroDelFormato()`: su registro principal
 *    y, en un centro, el suyo), el mismo bajo el que viven su firma y su sello y bajo el que escriben
 *    `RecipeFormatoController` y `ConfiguracionMedicoController`: una sola fila por médico.
 *  - **Sin fila no se crea nada hasta esta primera elección**: quien nunca guardó una opción lee los defaults
 *    (`OpcionesDeRecipe`) sin que exista la fila.
 *  - Sólo se escriben las **dos columnas nuevas** (nullable): el resto de la fila —`elementos`, firma, sello— no se
 *    toca. Y en una fila que **ya existe** no se mueve `updated_at`: el móvil lo usa como marca de versión para
 *    decidir si vuelve a bajar la firma y el sello (`RecipeFormatoResource`), y cambiar de "comercial" a
 *    "genérico" no es un cambio de esas imágenes.
 */
final class GuardarOpcionesDeImpresion
{
    /**
     * @throws InvalidArgumentException si el modo o el nombre no son de los permitidos, o el médico no tiene un
     *                                  registro bajo el cual guardar
     */
    public function ejecutar(Medico $medico, string $modo, string $nombre): RecipeFormato
    {
        if (! in_array($modo, RecipeFormato::MODOS_IMPRESION, true)) {
            throw new InvalidArgumentException('El modo de impresión elegido no es válido.');
        }

        if (! in_array($nombre, RecipeFormato::NOMBRES_MEDICAMENTO, true)) {
            throw new InvalidArgumentException('El nombre del medicamento elegido no es válido.');
        }

        $registro = $medico->registroDelFormato();

        if ($registro === null || trim($registro) === '') {
            throw new InvalidArgumentException('Este médico no tiene un número de registro bajo el cual guardar el formato.');
        }

        $formato = RecipeFormato::firstOrNew(['reg_medico' => $registro]);

        $formato->modo_impresion = $modo;
        $formato->nombre_medicamento = $nombre;

        if ($formato->exists) {
            $formato->timestamps = false;
        }

        $formato->save();

        return $formato;
    }
}
