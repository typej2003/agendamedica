<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las **opciones de impresión del récipe** del médico (WEB-5.2): cómo sale la hoja la última vez que se
 * imprimió, para que el médico y su asistente impriman igual sin volver a elegir.
 *
 * `recipe_formatos` es una tabla **nueva de AppDDR** (no del legado), una fila por médico, y sólo se le
 * agregan columnas **nullable**: la lee y la escribe también el API del móvil (`RecipeFormatoController`,
 * `RecipeFormatoResource` en `sync-app-data`) y ninguno de los dos conoce estas columnas, que no viajan al
 * teléfono.
 *
 * - `modo_impresion`: `rp_indicaciones` (una hoja: Rp. para la farmacia + Indicaciones para el paciente) o
 *   `solo_indicaciones` (media hoja). `NULL` = el valor por defecto, `rp_indicaciones`.
 * - `nombre_medicamento`: `comercial` o `generico`, con cuál nombre sale el medicamento. `NULL` = `comercial`.
 */
class AddOpcionesDeImpresionARecipeFormatos extends Migration
{
    public function up()
    {
        Schema::table('recipe_formatos', function (Blueprint $table) {
            $table->string('modo_impresion', 20)->nullable()->after('sello_posicion');
            $table->string('nombre_medicamento', 20)->nullable()->after('modo_impresion');
        });
    }

    public function down()
    {
        Schema::table('recipe_formatos', function (Blueprint $table) {
            $table->dropColumn(['modo_impresion', 'nombre_medicamento']);
        });
    }
}
