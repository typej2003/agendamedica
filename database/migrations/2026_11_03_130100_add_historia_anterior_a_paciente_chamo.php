<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La «historia anterior» del niño** (2026-11-03): PediaReport la pide en su alta (`HISTORIA A.`) y no
 * tiene una columna propia en `paciente_chamo` —la guardaba en `cedula`, que es la cédula del paciente—.
 * Para no pisar la cédula, la web la guarda en esta columna **nullable nueva**.
 *
 * Las tablas del legado no se reestructuran: sólo se agrega una columna nullable, como manda la regla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paciente_chamo') && ! Schema::hasColumn('paciente_chamo', 'historia_anterior')) {
            Schema::table('paciente_chamo', function (Blueprint $table) {
                $table->string('historia_anterior', 20)->nullable()->after('historia');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('paciente_chamo') && Schema::hasColumn('paciente_chamo', 'historia_anterior')) {
            Schema::table('paciente_chamo', function (Blueprint $table) {
                $table->dropColumn('historia_anterior');
            });
        }
    }
};
