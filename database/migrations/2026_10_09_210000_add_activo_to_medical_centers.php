<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `medical_centers.activo` — la baja de una **sede**.
 *
 * Una sede no se borra: `cola.medical_center_id` la referencia y las citas viejas no pueden perder su
 * jornada (mismo criterio que el `office_id` que a propósito no se guarda). Lo que hacía falta era
 * poder **cerrarla** para que deje de ofrecerse al agendar y al crear un consultorio, sin tocar el
 * historial. Es la misma decisión que ya se tomó con las cuentas ("archivar, no eliminar").
 *
 * Nace `true` para las 3 sedes que ya existen —hoy se usan—: sin default no habría forma de
 * distinguir las viejas de las nuevas, y el default `false` habría apagado lo que está en uso.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('medical_centers', 'activo')) {
            return;
        }

        Schema::table('medical_centers', function (Blueprint $table) {
            $table->boolean('activo')->default(true)->after('phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('medical_centers', 'activo')) {
            return;
        }

        Schema::table('medical_centers', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
    }
};
