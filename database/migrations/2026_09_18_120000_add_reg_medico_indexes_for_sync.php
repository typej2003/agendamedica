<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para el sync delta de AppDDR (`SyncAppDataController::sync()`, ver
 * Docs/Wiki/12-arquitectura-offline-sync.md). Cada tabla del pull delta se filtraba por
 * `reg_medico`/`numhistoria`/`nrohistoria` + `updated_at` sin ningún índice que lo cubriera,
 * forzando full table scan en cada sincronización. `medico_registros.medico_id` también se
 * agrega: es la primera query de `sync()`, resuelve los `reg_medico` del médico autenticado
 * y corre sin índice antes de todo lo demás.
 */
class AddRegMedicoIndexesForSync extends Migration
{
    public function up()
    {
        Schema::table('cola', function (Blueprint $table) {
            $table->index(['reg_medico', 'updated_at'], 'cola_reg_medico_updated_idx');
        });

        Schema::table('historias', function (Blueprint $table) {
            $table->index(['reg_medico', 'updated_at'], 'historias_reg_medico_updated_idx');
            $table->index(['numhistoria', 'updated_at'], 'historias_numhistoria_updated_idx');
        });

        Schema::table('consultas', function (Blueprint $table) {
            $table->index(['numhistoria', 'updated_at'], 'consultas_numhistoria_updated_idx');
        });

        Schema::table('motivos_consulta', function (Blueprint $table) {
            $table->index(['reg_medico', 'updated_at'], 'motivos_consulta_reg_medico_updated_idx');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->index(['nrohistoria', 'updated_at'], 'recipes_nrohistoria_updated_idx');
        });

        Schema::table('medico_registros', function (Blueprint $table) {
            $table->index('medico_id', 'medico_registros_medico_id_idx');
        });
    }

    public function down()
    {
        Schema::table('cola', function (Blueprint $table) {
            $table->dropIndex('cola_reg_medico_updated_idx');
        });

        Schema::table('historias', function (Blueprint $table) {
            $table->dropIndex('historias_reg_medico_updated_idx');
            $table->dropIndex('historias_numhistoria_updated_idx');
        });

        Schema::table('consultas', function (Blueprint $table) {
            $table->dropIndex('consultas_numhistoria_updated_idx');
        });

        Schema::table('motivos_consulta', function (Blueprint $table) {
            $table->dropIndex('motivos_consulta_reg_medico_updated_idx');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex('recipes_nrohistoria_updated_idx');
        });

        Schema::table('medico_registros', function (Blueprint $table) {
            $table->dropIndex('medico_registros_medico_id_idx');
        });
    }
}
