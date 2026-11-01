<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sync_cargas.modo`: cómo se aplicó (o se está aplicando) la carga inicial.
 *
 *  - `limpia`: el consultorio no tenía datos; el camino de siempre, en bloque.
 *  - `mezcla`: el consultorio **ya tenía datos** (la web o el app se usaron antes). El escritorio sigue
 *    siendo la fuente absoluta, pero los registros que ocupaban un número se mueven (ver
 *    `App\Sync\Escritorio\Traslados` y la tabla `sync_traslados`).
 *
 * Nullable a propósito: una carga a medio camino de antes de este cambio no tiene modo y se sigue
 * tratando como `limpia`, que es como se comportaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sync_cargas', 'modo')) {
            Schema::table('sync_cargas', function (Blueprint $table) {
                $table->string('modo', 20)->nullable()->after('estado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sync_cargas', 'modo')) {
            Schema::table('sync_cargas', function (Blueprint $table) {
                $table->dropColumn('modo');
            });
        }
    }
};
