<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bloqueos_de_paciente`: la fuente de verdad de los bloqueos de pacientes-cuenta con alcance por cuenta
 * (`user_id`), por teléfono (normalizado, con `vence_en`) o por cédula (sólo dígitos). Una fila por alcance.
 * Un bloqueo está vigente si `levantado_en` es nulo y `vence_en` es nulo o futuro. Los bloqueos antiguos por
 * `users.is_active = false` siguen valiendo como bloqueo de cuenta.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('bloqueos_de_paciente', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('telefono', 20)->nullable()->index();
            $table->string('cedula', 20)->nullable()->index();
            $table->string('motivo', 255)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('vence_en')->nullable();
            $table->timestamp('levantado_en')->nullable();
            $table->unsignedBigInteger('levantado_por')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('bloqueos_de_paciente');
    }
};
