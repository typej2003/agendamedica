<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitaciones de un médico a otro para unirse al equipo de un `reg_medico` (2026-10-28).
 *
 * Es una tabla **nueva de AppDDR**: no reemplaza a `medico_registros` (que sigue siendo el vínculo de
 * acceso) ni a `medicos`. La invitación es el consentimiento previo; al aceptarse se crea la fila de
 * `medico_registros` y la invitación queda como historial.
 *
 * `medico_id` se llena al crear si el correo ya es de un médico; si no, se resuelve al aceptar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitaciones_de_medico', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 50);
            $table->string('email', 150);
            $table->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            $table->foreignId('invitada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('estado', 12)->default('pendiente'); // pendiente | aceptada | rechazada | revocada | vencida
            $table->dateTime('expira_el');
            $table->dateTime('aceptada_el')->nullable();
            $table->timestamps();

            $table->index(['reg_medico', 'estado']);
            $table->index(['email', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones_de_medico');
    }
};
