<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La colposcopía con imagen editable (A-13): el trazo de color que el médico marca sobre la **imagen del cuello
 * uterino**, por consulta y por variante (`nulipara` / `multipara`), más la **imagen base** que eligió para esa
 * variante (`vector` o `escritorio`).
 *
 * Tabla **nueva de AppDDR**, no del legado: el escritorio dibujaba sobre un archivo local (`imagen_pacientes_2`) y
 * no guardaba nada en la base. Guarda sólo **el trazo** (un PNG transparente en el disco privado `clinica`) y la
 * elección de la base; la imagen de fondo la pone la pantalla, así que cambiar la base no borra el dibujo (y el
 * trazo sirve para imprimir sin arrastrar el fondo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dibujos_de_colposcopia', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            // Como `dibujos_de_mamas` y `imagen_consulta`: el número de historia y el de la consulta dentro de la historia.
            $table->unsignedInteger('nrohistoria');
            $table->unsignedInteger('nroconsulta');
            $table->string('variante', 12); // nulipara | multipara
            $table->string('base', 12)->default('vector'); // vector | escritorio
            // Ruta relativa al disco `clinica`. Es **nullable** a propósito: la fila se queda con la base elegida aunque
            // el médico todavía no haya dibujado (o haya borrado todo el trazo).
            $table->string('archivo', 255)->nullable();
            $table->timestamps();

            $table->unique(['reg_medico', 'nrohistoria', 'nroconsulta', 'variante'], 'dibujos_de_colposcopia_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dibujos_de_colposcopia');
    }
};
