<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La orden de laboratorio de la pareja (WEB-11.21): la C.I., el nombre y el **resumen clínico** del diálogo «Examen de la Pareja», una fila por
 * consulta. Tabla **nueva de AppDDR**: el escritorio repite la C.I. y el nombre en cada examen (`examen_pareja`, que se sigue llenando) y comparte
 * el resumen con el de la paciente (`examen_obs`); acá la orden se recarga entera aunque todavía no tenga exámenes y los dos resúmenes no se mezclan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examen_pareja_datos', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            $table->unsignedInteger('nrohistoria');
            $table->unsignedInteger('nroconsulta');
            $table->string('cedula', 20)->nullable();
            $table->string('nombre', 100)->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();

            $table->unique(['reg_medico', 'nrohistoria', 'nroconsulta'], 'examen_pareja_datos_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examen_pareja_datos');
    }
};
