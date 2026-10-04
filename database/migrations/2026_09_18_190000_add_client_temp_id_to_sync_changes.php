<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `client_temp_id` — el id temporal (negativo) que el cliente le asignó a una fila que creó
 * estando offline, antes de que el servidor le diera uno real.
 *
 * Existe por idempotencia: si el cliente manda un `created` y la respuesta se pierde (señal mala,
 * justo el escenario que motivó todo el sync), va a reintentar el mismo cambio. Sin esto, cada
 * reintento crearía una cita duplicada. Con esto, el servidor reconoce "esta fila ya la creé para
 * este temp_id" y devuelve el mismo id real sin volver a insertar.
 */
class AddClientTempIdToSyncChanges extends Migration
{
    public function up()
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->bigInteger('client_temp_id')->nullable()->after('record_id');

            $table->index(['table_name', 'client_temp_id'], 'sync_changes_temp_id_idx');
        });
    }

    public function down()
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->dropIndex('sync_changes_temp_id_idx');
            $table->dropColumn('client_temp_id');
        });
    }
}
