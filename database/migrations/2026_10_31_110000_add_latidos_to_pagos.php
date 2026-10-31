<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **El desglose de Latidos en un pago** (2026-10-31, fase 2).
 *
 * `monto_usd_e2` sigue siendo el **total** de la venta. Lo que se agrega es cuánto de ese total se cubrió
 * con Latidos (`latidos_usados` y su valor congelado en `descuento_usd_e2`) y cuánto se le cobró a la
 * pasarela (`monto_pasarela_usd_e2`; `monto_e2` sigue siendo lo que se cobra en la moneda del método).
 *
 * Las filas viejas quedan con `latidos_usados = 0`, `descuento_usd_e2 = 0` y
 * `monto_pasarela_usd_e2 = monto_usd_e2`: es exactamente lo que hacían (todo por la pasarela).
 *
 * `latido_id` es la fila de Latidos del consultorio que se usó; sin clave foránea dura no se borra el
 * historial del pago si algún día se limpia una fila de Latidos, pero con `nullOnDelete` el pago queda
 * huérfano y se puede auditar igual. Es aditiva y con guardas por columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pagos')) {
            return;
        }

        if (! Schema::hasColumn('pagos', 'latidos_usados')) {
            Schema::table('pagos', function (Blueprint $table) {
                $table->unsignedBigInteger('latidos_usados')->default(0);
                $table->unsignedBigInteger('descuento_usd_e2')->default(0);
                $table->unsignedBigInteger('monto_pasarela_usd_e2')->default(0);
                $table->foreignId('latido_id')->nullable()->constrained('latidos')->nullOnDelete();
            });
        }

        // Backfill: antes no había Latidos, todo el monto iba por la pasarela.
        DB::table('pagos')
            ->where('monto_pasarela_usd_e2', 0)
            ->where('monto_usd_e2', '>', 0)
            ->update(['monto_pasarela_usd_e2' => DB::raw('monto_usd_e2')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pagos') || ! Schema::hasColumn('pagos', 'latidos_usados')) {
            return;
        }

        Schema::table('pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('latido_id');
            $table->dropColumn(['latidos_usados', 'descuento_usd_e2', 'monto_pasarela_usd_e2']);
        });
    }
};
