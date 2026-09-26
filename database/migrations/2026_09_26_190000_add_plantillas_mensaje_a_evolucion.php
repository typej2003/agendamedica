<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de mensaje editables (Paso 23): "Plantilla de citas" y "Plantilla de cumpleaños" de
 * `ConfiguracionScreen`, hoy stub. No existen en el esquema legado (no es un campo migrado, se
 * confirmó contra el dump SQL) — son dos columnas nuevas y nullable, mismo criterio que el resto
 * de columnas nuevas sobre `evolucion` (AGENTS.md raíz: solo se agregan, nunca se renombran ni
 * restructuran las existentes de una tabla que sincroniza datos reales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evolucion', function (Blueprint $table) {
            $table->string('plantilla_cita', 500)->nullable()->after('lineag_2');
            $table->string('plantilla_cumple', 500)->nullable()->after('plantilla_cita');
        });
    }

    public function down(): void
    {
        Schema::table('evolucion', function (Blueprint $table) {
            $table->dropColumn(['plantilla_cita', 'plantilla_cumple']);
        });
    }
};
