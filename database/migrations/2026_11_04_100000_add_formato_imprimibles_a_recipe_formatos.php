<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La hoja de los imprimibles, elegida por el médico** (2026-11-04): una columna nullable en `recipe_formatos` con la
 * forma en que salen sus documentos de texto —vertical, media vertical, horizontal o estilo récipe—. `NULL` es «como
 * hasta hoy»: el motor sigue aplicando el modo que declaraba cada documento. Los récipes no la leen: siempre son media
 * hoja horizontal.
 *
 * Es una tabla nueva de AppDDR (una fila por médico), así que la columna es aditiva y no toca el modelo legado. No entra
 * en `RecipeFormatoResource`: el API del móvil conserva sus claves y el teléfono no cambia (alcance de esta tarea).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('recipe_formatos', 'formato_imprimibles')) {
            Schema::table('recipe_formatos', function (Blueprint $table) {
                $table->string('formato_imprimibles', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('recipe_formatos', 'formato_imprimibles')) {
            Schema::table('recipe_formatos', function (Blueprint $table) {
                $table->dropColumn('formato_imprimibles');
            });
        }
    }
};
