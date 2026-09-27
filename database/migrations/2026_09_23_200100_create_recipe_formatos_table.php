<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formato de impresión del récipe, una fila por médico (ROADMAP.md Paso 18.A). Es una tabla **nueva de
 * AppDDR**: en el legado esto vivía en `recipe.ini`, un archivo por PC que nunca llegó a la base (ver
 * PENDIENTES-POWERBUILDER.md), así que no hay esquema legado que respetar.
 *
 * - `elementos`: JSON con la configuración de cada bloque del membrete (logo, médico, especialidad,
 *   rif, pie). Es JSON y no una columna por opción porque son ~7 opciones × 5 elementos, siempre se
 *   leen y escriben juntas, y el conjunto va a crecer (tipografía, colores) cuando se use de verdad.
 * - `firma` / `sello`: rutas en Storage (disco `public`). Antes vivían solo en el teléfono (Paso 16);
 *   se suben para que la asistente imprima con la firma del médico desde su propio teléfono.
 */
class CreateRecipeFormatosTable extends Migration
{
    public function up()
    {
        Schema::create('recipe_formatos', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->unique();
            $table->json('elementos')->nullable();
            $table->string('color_linea', 20)->nullable();
            $table->string('firma', 500)->nullable();
            $table->string('sello', 500)->nullable();
            $table->string('sello_posicion', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('recipe_formatos');
    }
}
