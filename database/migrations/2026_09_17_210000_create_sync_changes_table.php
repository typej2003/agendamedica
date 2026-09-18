<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de cambios para el sync delta de AppDDR (ver Docs/Wiki/12-arquitectura-offline-sync.md
 * y ROADMAP.md). Un registro por operación:
 *
 * - created / deleted: `column_name`/`value` van en null, la fila entera es el marcador.
 * - updated: una fila por columna que cambió (`column_name` + `value` con el valor nuevo).
 *
 * Las eliminaciones se consultan filtrando esta misma tabla por `operation = 'deleted'` — no hace
 * falta una tabla de tombstones aparte.
 */
class CreateSyncChangesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            $table->string('table_name', 64);
            $table->unsignedBigInteger('record_id');
            $table->string('operation', 10);
            $table->string('column_name', 64)->nullable();
            $table->text('value')->nullable();
            $table->dateTime('occurred_at', 3);
            $table->string('source', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reg_medico', 'table_name', 'occurred_at'], 'sync_changes_delta_idx');
            $table->index(['table_name', 'record_id'], 'sync_changes_record_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sync_changes');
    }
}
