<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La política de verificación de médicos, en una sola fila** (2026-11-03). Hoy tiene un solo
 * interruptor: si el registro exige el carnet verificado (`exigir_carnet`).
 *
 * Nace **apagado**: el médico entra solo al confirmar su correo y su carnet queda `sin_verificar` /
 * `pendiente`, que es lo que pidió Alexander para no frenar el alta. Al encenderlo, el registro vuelve a
 * esperar la aprobación del administrador y un médico con el carnet sin verificar no puede usar la app.
 *
 * Tabla nueva de AppDDR; la fila la crea `App\Models\ConfiguracionDeVerificacion::actual()` la primera
 * vez, igual que `configuracion_latidos`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_verificacion_medicos', function (Blueprint $table) {
            $table->id();
            $table->boolean('exigir_carnet')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_verificacion_medicos');
    }
};
