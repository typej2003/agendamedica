<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las dos columnas que faltaban de traumatología** (T3, 2026-11-10).
 *
 * La migración `2026_11_04_150000_create_tablas_cardiologia` creó `implante` y `orden_ingreso` para que el sync no las
 * perdiera (en cardiología no las escribe ninguna ventana, C11 «no aplica»), pero **sin dos columnas que el escritorio
 * de traumatología sí usa**: `implante.puro_texto` (la hoja «SOLICITUD IMPLANTE» en texto libre, `d_solicitud_implante.srd`)
 * y `orden_ingreso.detalle` (las «Observaciones» de la orden, `d_orden_ingreso_3.srd`). Las dos están en el esquema ASA
 * de traumatología (`esquemas-asa/traumatologia.sql`) y las dos son **nullable**: sólo se agregan, no se toca nada más.
 *
 * La migración de cardiología ya está desplegada, así que la corrección va acá y no allá (regla de migraciones).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('implante') && ! Schema::hasColumn('implante', 'puro_texto')) {
            Schema::table('implante', function (Blueprint $table) {
                $table->text('puro_texto')->nullable()->after('tipo');
            });
        }

        if (Schema::hasTable('orden_ingreso') && ! Schema::hasColumn('orden_ingreso', 'detalle')) {
            Schema::table('orden_ingreso', function (Blueprint $table) {
                $table->text('detalle')->nullable()->after('reco_avisar');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('implante') && Schema::hasColumn('implante', 'puro_texto')) {
            Schema::table('implante', function (Blueprint $table) {
                $table->dropColumn('puro_texto');
            });
        }

        if (Schema::hasTable('orden_ingreso') && Schema::hasColumn('orden_ingreso', 'detalle')) {
            Schema::table('orden_ingreso', function (Blueprint $table) {
                $table->dropColumn('detalle');
            });
        }
    }
};
