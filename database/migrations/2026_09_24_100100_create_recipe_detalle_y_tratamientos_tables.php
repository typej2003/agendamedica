<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas **legadas** del récipe que el Paso 18.B necesita (ver Docs/Wiki/05-recipes-y-solicitudes.md →
 * "Tablas legadas involucradas"). Mismo criterio que `create_recipes_table`: el guard `hasTable` las
 * crea en local y no toca las del despliegue. Columnas idénticas al dump, no renombrar.
 *
 * - `recipe_detalle`: cabecera de cada récipe (emisión, vencimiento en días, nota).
 * - `recipe_grupo` / `recipe_grupo_detalle`: los **tratamientos**, plantillas por médico que cargan
 *   varios medicamentos con sus indicaciones de un toque.
 */
class CreateRecipeDetalleYTratamientosTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('recipe_detalle')) {
            Schema::create('recipe_detalle', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable()->index();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->integer('recipe');
                $table->date('fe_emision')->nullable();
                $table->integer('fe_vence')->nullable();
                $table->text('nota')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('recipe_grupo')) {
            Schema::create('recipe_grupo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable()->index();
                $table->string('codigo', 4);
                $table->string('tratamiento', 100)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('recipe_grupo_detalle')) {
            Schema::create('recipe_grupo_detalle', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable()->index();
                $table->string('codigo', 4);
                $table->string('codemedicina', 8);
                $table->string('descripcion', 100)->nullable();
                $table->text('indicaciones')->nullable();
                $table->integer('cantidad')->nullable();
                $table->integer('orden')->nullable();
                $table->string('sico', 1)->nullable();
                $table->string('nombrecomercial1', 40)->nullable();
                $table->string('nombrecomercial2', 40)->nullable();
                $table->string('nombrecomercial3', 40)->nullable();
                $table->text('totalre')->nullable();
                $table->string('sicome', 1)->nullable();
                $table->string('sicome1', 1)->nullable();
                $table->string('sicome2', 1)->nullable();
                $table->string('sicome3', 1)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        // No se borran: contra el despliegue son tablas legadas con datos que esta migración no creó.
    }
}
