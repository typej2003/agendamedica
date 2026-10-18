<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imágenes de mamas (WEB-11.10): el trazo de color que el médico marca sobre la imagen base de cada mama, **por consulta**.
 *
 * Tabla **nueva de AppDDR**, no del legado (el escritorio abría el Paint sobre un archivo local por paciente y no guardaba nada
 * en la base): no entra al sync del escritorio y se diseña libre. Guarda sólo **el trazo** (un PNG transparente en el disco
 * privado `clinica`); la imagen base la pone la pantalla, así que cambiar la base no rompe los dibujos viejos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dibujos_de_mamas', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            // Como `imagen_consulta`: el número de historia y el de la consulta dentro de la historia.
            $table->unsignedInteger('nrohistoria');
            $table->unsignedInteger('nroconsulta');
            $table->string('lado', 10); // derecha | izquierda (de la paciente)
            $table->string('archivo', 255); // ruta relativa al disco `clinica`
            $table->timestamps();

            $table->unique(['reg_medico', 'nrohistoria', 'nroconsulta', 'lado'], 'dibujos_de_mamas_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dibujos_de_mamas');
    }
};
