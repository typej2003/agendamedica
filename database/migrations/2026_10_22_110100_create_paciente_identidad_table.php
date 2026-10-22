<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `paciente_identidad`: la **cédula confirmada** de un paciente (identidad por niveles, nivel 2). Una fila por
 * ficha cuando la secretaria o el médico vieron la cédula laminada en la consulta. Tabla propia para no tocar
 * `pacientes`, que se sincroniza con el escritorio.
 *
 * `cedula` guarda la cédula confirmada (sólo dígitos): si después la ficha cambia de cédula, la confirmación ya
 * no vale. `reg_medico` es el consultorio (tenant) desde el que se confirmó.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('paciente_identidad', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('paciente_id')->unique();
            $table->string('reg_medico', 50)->nullable();
            $table->string('cedula', 20)->index();
            $table->timestamp('cedula_confirmada_en');
            $table->unsignedBigInteger('confirmada_por_user_id')->nullable();
            $table->string('nota', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('paciente_identidad');
    }
};
