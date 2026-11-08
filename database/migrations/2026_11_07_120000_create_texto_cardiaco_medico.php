<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Los textos por defecto de los informes del cardiólogo** (C12, 2026-11-07).
 *
 * El escritorio los guarda en `texto_cardiaco`, una **sola fila** (`clave` = `1`) sin `reg_medico`: es global de la
 * instalación. La web es multi-consultorio, así que los guarda **por médico** en esta tabla propia y los usa para
 * precargar el texto del Doppler de miembros superiores, el protocolo del marcapasos y su texto de marca, como el
 * escritorio (`w_consulta_principal.srw:3860-3883`).
 *
 * Tabla **nueva** de AppDDR: no viaja por el sync del escritorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('texto_cardiaco_medico')) {
            return;
        }

        Schema::create('texto_cardiaco_medico', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->unique();
            $table->text('duplex_superior')->nullable();
            $table->text('protocolo_marcapaso')->nullable();
            $table->text('texto_marca')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('texto_cardiaco_medico');
    }
};
