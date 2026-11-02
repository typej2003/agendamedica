<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Quién cobra las citas de cada médico**: la plataforma (por defecto) o el propio consultorio.
 *
 * Es una fila por `reg_medico`. No toca ninguna tabla legada: es una preferencia de AppDDR.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('preferencias_de_cobro')) {
            Schema::create('preferencias_de_cobro', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 50)->unique();
                $table->string('recaudador', 12)->default('plataforma');   // plataforma | medico
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preferencias_de_cobro');
    }
};
