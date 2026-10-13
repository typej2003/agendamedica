<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WEB-8.5 — la bitácora de cambios: **quién** hizo cada escritura de la web.
 *
 * `sync_changes` nació como el rastro del sync (qué cambió, cuándo y desde dónde: `source`) y no tenía usuario. Desde ahora
 * toda escritura con `source = web` anota la cuenta que la hizo (`user_id`); lo anterior queda en `NULL` ("sin usuario": no hay
 * de dónde recuperarlo), igual que lo que escriben el móvil, el escritorio y las migraciones, que no tienen una sesión web.
 *
 * Es una tabla de AppDDR (no del legado) y la columna es nullable y sin llave foránea a propósito: una cuenta que se borre no
 * puede arrastrar ni bloquear el rastro del sync. **El sync no la lee ni la expone** (`eliminados` pide sus tres columnas por
 * nombre), así que el JSON del móvil no cambia.
 *
 * El índice `(reg_medico, occurred_at)` es el de la pantalla Bitácora: lo más reciente de un registro, sin recorrer la tabla.
 */
class AddUserIdASyncChanges extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('sync_changes', 'user_id')) {
            Schema::table('sync_changes', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('source');
            });
        }

        Schema::table('sync_changes', function (Blueprint $table) {
            $table->index(['reg_medico', 'occurred_at'], 'sync_changes_bitacora_idx');
            $table->index(['reg_medico', 'user_id', 'occurred_at'], 'sync_changes_bitacora_usuario_idx');
        });
    }

    public function down()
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->dropIndex('sync_changes_bitacora_idx');
            $table->dropIndex('sync_changes_bitacora_usuario_idx');
            $table->dropColumn('user_id');
        });
    }
}
