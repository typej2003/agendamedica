<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La hoja de cada imprimible, elegida por el médico** (2026-11-05): una columna JSON nullable en `recipe_formatos` con la
 * forma en que sale **cada documento de texto** —`{"constancia":"media-vertical","reposo":"completa"}`—. `NULL` o una clave
 * que falta es «la hoja de siempre» de ese documento (el A4 vertical completo de las solicitudes y la media horizontal del
 * resumen médico).
 *
 * Reemplaza a `formato_imprimibles` (2026-11-04), que era **una sola** hoja para todos: aquella columna queda como está
 * —nadie la lee— para no borrar datos de una base que ya la tenga.
 *
 * Es una tabla nueva de AppDDR (una fila por médico), así que la columna es aditiva. No entra en `RecipeFormatoResource`:
 * el API del móvil conserva sus claves y el teléfono no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('recipe_formatos', 'hojas_de_los_imprimibles')) {
            Schema::table('recipe_formatos', function (Blueprint $table) {
                $table->json('hojas_de_los_imprimibles')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('recipe_formatos', 'hojas_de_los_imprimibles')) {
            Schema::table('recipe_formatos', function (Blueprint $table) {
                $table->dropColumn('hojas_de_los_imprimibles');
            });
        }
    }
};
