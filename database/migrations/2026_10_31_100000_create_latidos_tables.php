<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **El programa de Latidos** (2026-10-31).
 *
 * Los Latidos son una **unidad de descuento promocional**, no dinero, no una wallet y no una cuenta de
 * pago: 100 Latidos = USD 1 de descuento. No se retiran, no se transfieren y sólo se canjean en productos
 * o servicios de la plataforma. El nombre y el modelo evitan a propósito “saldo”, “billetera” y “USD”.
 *
 * Tablas nuevas de AppDDR (no del legado), sólo del servidor: no están en `config/sync_legado.php` ni
 * bajan al móvil. La fila de `latidos` es por `reg_medico`, igual que el plan y el servicio.
 *
 *  - `latidos`: los disponibles de cada consultorio. `renovacion_automatica` nace **encendida** (decisión
 *    del usuario, 2026-10-30): si hay Latidos suficientes, el plan se paga solo el día del vencimiento.
 *  - `movimientos_de_latidos`: libro inmutable; el disponible es la suma.
 *  - `retenciones_de_latidos`: lo reservado por un pago pendiente, para que dos pestañas no usen lo mismo.
 *  - `configuracion_latidos`: la fila única del programa. La compra de paquetes nace **apagada**.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('latidos')) {
            return;
        }

        Schema::create('latidos', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 50)->unique();
            // Caché del libro; la verdad es la suma de `movimientos_de_latidos`.
            $table->bigInteger('disponibles')->default(0);
            // Decisión del 2026-10-30: se usa para pagar el plan por defecto.
            $table->boolean('renovacion_automatica')->default(true);
            $table->foreignId('renovacion_plan_id')->nullable()->constrained('planes')->nullOnDelete();
            $table->unsignedTinyInteger('renovacion_meses')->nullable();
            $table->timestamps();
        });

        Schema::create('movimientos_de_latidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('latido_id')->constrained('latidos')->cascadeOnDelete();
            // compra | referido | promocion | descuento_plan | ajuste | reverso
            $table->string('tipo', 24);
            // Con signo: positivo acredita, negativo descuenta.
            $table->bigInteger('latidos');
            $table->bigInteger('disponibles_antes')->default(0);
            $table->bigInteger('disponibles_despues')->default(0);
            // Evita el doble crédito por webhook repetido: `referido:12:referente`, `plan:34`, etc.
            $table->string('clave_idempotencia', 80)->unique();
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();
            // La FK a `referidos` se agrega en la migración de referidos, para no depender del orden.
            $table->unsignedBigInteger('referido_id')->nullable();
            $table->unsignedBigInteger('autor_id')->nullable();
            $table->string('concepto', 150);
            $table->text('nota')->nullable();
            $table->timestamps();

            $table->index(['latido_id', 'id'], 'movimientos_latidos_latido_idx');
            $table->index(['latido_id', 'created_at'], 'movimientos_latidos_fecha_idx');
        });

        Schema::create('retenciones_de_latidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('latido_id')->constrained('latidos')->cascadeOnDelete();
            // Un pago no puede tener dos retenciones.
            $table->foreignId('pago_id')->unique()->constrained('pagos')->cascadeOnDelete();
            $table->unsignedBigInteger('latidos');
            // activa | consumida | liberada
            $table->string('estado', 10)->default('activa');
            $table->timestamp('expira_el')->nullable();
            $table->timestamps();

            $table->index(['latido_id', 'estado'], 'retenciones_latidos_estado_idx');
        });

        Schema::create('configuracion_latidos', function (Blueprint $table) {
            $table->id();
            // La compra de paquetes nace apagada: el MVP sólo gana Latidos por referidos y promociones.
            $table->boolean('compra_activa')->default(false);
            $table->boolean('referidos_activos')->default(true);
            // 2.000 Latidos = USD 20 de descuento (100 Latidos = USD 1).
            $table->unsignedBigInteger('latidos_referente')->default(2000);
            $table->unsignedBigInteger('latidos_referido')->default(2000);
            $table->unsignedSmallInteger('vigencia_atribucion_dias')->default(90);
            $table->unsignedSmallInteger('tope_mensual_por_referente')->default(0);
            $table->string('url_descarga', 255)->nullable();
            $table->timestamps();
        });

        // La fila única, si la tabla acaba de nacer. `ConfiguracionLatidos::actual()` también la crea.
        if (DB::table('configuracion_latidos')->count() === 0) {
            DB::table('configuracion_latidos')->insert([
                'compra_activa' => false,
                'referidos_activos' => true,
                'latidos_referente' => 2000,
                'latidos_referido' => 2000,
                'vigencia_atribucion_dias' => 90,
                'tope_mensual_por_referente' => 0,
                'url_descarga' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_latidos');
        Schema::dropIfExists('retenciones_de_latidos');
        Schema::dropIfExists('movimientos_de_latidos');
        Schema::dropIfExists('latidos');
    }
};
