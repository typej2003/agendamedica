<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A qué especialidades aplica cada fila del catálogo inicial** (WEB-12.9, decisión del usuario, 2026-10-21).
 *
 * Las filas iniciales viven en las tablas del médico con `reg_medico` NULL (`vademecum`, `diagnosticos`…), que son
 * tablas del legado: no se les agrega una columna por esto. La marca va en este puente:
 *
 *  - **sin filas acá, la fila es general**: la recibe cualquier médico, sea de la especialidad que sea;
 *  - con una o más filas, la reciben sólo los médicos de **esas** especialidades.
 *
 * `catalogo` es el slug de `CatalogosIniciales` (`medicamentos`, `examenes`…) y `fila_id` el `id` de la fila inicial en
 * la tabla de ese catálogo. Las líneas de un tratamiento no se marcan: siguen a su tratamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalogo_inicial_especialidad')) {
            return;
        }

        Schema::create('catalogo_inicial_especialidad', function (Blueprint $table) {
            $table->id();
            $table->string('catalogo', 40);
            $table->unsignedBigInteger('fila_id');
            $table->unsignedBigInteger('specialty_id');
            $table->timestamps();

            $table->unique(['catalogo', 'fila_id', 'specialty_id'], 'catalogo_inicial_especialidad_unica');
            $table->index('specialty_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_inicial_especialidad');
    }
};
