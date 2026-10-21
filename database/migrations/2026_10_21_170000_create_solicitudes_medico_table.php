<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Registro de médicos con aprobación** (ROADMAP §10.4, fase 2): la solicitud que un médico manda desde
 * `/registro/medico` y que un administrador aprueba o rechaza en `/admin/solicitudes-medicos`.
 *
 * Tabla **nueva de AppDDR**, no del legado: no entra al sync del escritorio ni del app, así que se diseña libre.
 * La solicitud **no es una cuenta**: el `User` y la ficha de `medicos` nacen recién al aprobarla (por
 * `CuentaService`), y entonces se anotan acá `medico_id` y el `reg_medico` que confirmó el administrador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_medico', function (Blueprint $table) {
            $table->id();

            $table->string('prefijo', 20)->nullable();
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('cedula', 20);
            // Lo que el administrador valida para saber que el médico es legítimo.
            $table->string('mpps', 30);
            $table->string('colegio', 30)->nullable();
            // Sin clave foránea: la especialidad se confirma al aprobar y el catálogo lo siembran los manifiestos.
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->string('telefono', 30);
            // Ya verificado con el código de 6 dígitos: es al que le llega la clave temporal.
            $table->string('email', 120);
            $table->string('ciudad', 100)->nullable();
            $table->text('mensaje')->nullable();

            // La foto del carnet (opcional), en el disco privado `solicitudes`. Se sirve por una ruta autorizada.
            $table->string('carnet_ruta')->nullable();
            $table->string('carnet_tipo', 100)->nullable();
            $table->string('carnet_nombre')->nullable();

            $table->string('estado', 20)->default('pendiente');
            $table->text('motivo_rechazo')->nullable();
            $table->unsignedBigInteger('revisada_por')->nullable();
            $table->timestamp('revisada_el')->nullable();

            // Lo que quedó al aprobarla.
            $table->unsignedBigInteger('medico_id')->nullable();
            $table->string('reg_medico', 100)->nullable();

            $table->timestamps();

            // La bandeja se abre por estado, de lo más nuevo a lo más viejo; el alta busca duplicados por correo y cédula.
            $table->index(['estado', 'created_at']);
            $table->index('email');
            $table->index('cedula');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_medico');
    }
};
