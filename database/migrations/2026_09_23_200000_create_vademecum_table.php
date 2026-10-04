<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `vademecum` es una tabla del esquema LEGADO (ver Docs/Wiki/05-recipes-y-solicitudes.md → "Tablas
 * legadas involucradas"): catálogo de medicamentos **por médico** (`reg_medico`), con datos reales en
 * el dump. Mismo criterio que `create_recipes_table`: el `Schema::hasTable` guard la crea en local y no
 * toca la del despliegue, que ya existe con datos. Columnas idénticas al legado — no renombrar.
 *
 * Hace falta para imprimir récipes (ROADMAP.md Paso 18.A): `recipes.descripcion` viene NULL en casi
 * todas las filas reales, y el nombre del medicamento sale de acá por `codemedicina`.
 */
class CreateVademecumTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('vademecum')) {
            return;
        }

        Schema::create('vademecum', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->nullable()->index();
            $table->string('codemedicina', 8);
            $table->string('nombregenerico', 35)->nullable();
            $table->string('nombrecomercial', 35)->nullable();
            $table->text('dosificacion')->nullable();
            $table->text('uso')->nullable();
            $table->string('presentacion', 50)->nullable();
            $table->double('concentracion')->nullable();
            $table->double('cada')->nullable();
            $table->integer('durante')->nullable();
            $table->double('pvc')->nullable();
            $table->double('pvs')->nullable();
            $table->double('dosis')->nullable();
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

    public function down()
    {
        // No se borra, por el mismo motivo que `recipes`: contra el despliegue es una tabla con datos
        // reales que esta migración no creó.
    }
}
