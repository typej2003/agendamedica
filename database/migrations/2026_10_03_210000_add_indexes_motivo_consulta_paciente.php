<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para los motivos de consulta que ahora baja el app (ROADMAP.md Paso 18.B2). Hasta ahora
 * `motivo_consulta_paciente` no tenía ninguno: el delta filtra por `reg_medico` + historia y
 * `updated_at`, y al agregar un motivo se busca la fila de esa consulta por (registro, historia,
 * consulta). Con los datos reales del legado son decenas de miles de filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('motivo_consulta_paciente')) {
            return;
        }

        Schema::table('motivo_consulta_paciente', function (Blueprint $table) {
            $table->index(['reg_medico', 'nrohistoria', 'nroconsulta'], 'motivo_consulta_paciente_consulta_idx');
            $table->index(['reg_medico', 'updated_at'], 'motivo_consulta_paciente_reg_medico_updated_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('motivo_consulta_paciente')) {
            return;
        }

        Schema::table('motivo_consulta_paciente', function (Blueprint $table) {
            $table->dropIndex('motivo_consulta_paciente_consulta_idx');
            $table->dropIndex('motivo_consulta_paciente_reg_medico_updated_idx');
        });
    }
};
