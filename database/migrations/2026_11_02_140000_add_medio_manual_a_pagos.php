<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El medio con el que se cobró en el mostrador** (O-53, 2026-11-02).
 *
 * Un cobro en el consultorio puede entrar por la pasarela —y entonces tiene su `metodo_pago_id`— o
 * registrarse a mano porque el paciente pagó en efectivo, con punto de venta o por transferencia. Esos
 * cobros no tienen método de la plataforma, así que el medio viaja en su propia columna: es lo que la
 * pantalla de ingresos del médico muestra en la columna «medio».
 *
 * `pagos` es tabla de AppDDR (no del legado): la columna es nullable y no toca ninguna fila existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pagos') || Schema::hasColumn('pagos', 'medio_manual')) {
            return;
        }

        Schema::table('pagos', function (Blueprint $table) {
            // efectivo | punto_venta | transferencia | otro
            $table->string('medio_manual', 20)->nullable()->after('canal');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pagos') || ! Schema::hasColumn('pagos', 'medio_manual')) {
            return;
        }

        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('medio_manual');
        });
    }
};
