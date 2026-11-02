<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Pago móvil y transferencias**: la cuenta que los recibe y la conciliación de lo que entra.
 *
 * - `cuentas_bancarias` gana la decisión de cómo se confirma (`manual` o `automatica`), las credenciales
 *   del banco (cifradas, con el mismo cast de los métodos de pago) y el resultado de la última prueba.
 * - `pagos` gana lo confirmado y quién lo confirmó: el monto esperado sigue en `monto_e2`, y lo que el
 *   banco (o el médico) reconoce se suma en `monto_confirmado_e2`.
 * - `pago_transacciones` es una fila por transacción bancaria: el `Pago` es la intención y puede tener
 *   varias (un abono y su resto). La referencia es única por cuenta: la misma no se acredita dos veces.
 *
 * Las guardas son por columna y por tabla (trampa 167): la migración tiene que poder correr sobre una
 * base donde otra rama ya agregó algo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cuentas_bancarias')) {
            Schema::table('cuentas_bancarias', function (Blueprint $table) {
                if (! Schema::hasColumn('cuentas_bancarias', 'conciliacion')) {
                    $table->string('conciliacion', 12)->default('manual');
                }
                if (! Schema::hasColumn('cuentas_bancarias', 'credenciales')) {
                    $table->text('credenciales')->nullable();
                }
                if (! Schema::hasColumn('cuentas_bancarias', 'conciliacion_verificada_el')) {
                    $table->timestamp('conciliacion_verificada_el')->nullable();
                }
                if (! Schema::hasColumn('cuentas_bancarias', 'conciliacion_error')) {
                    $table->string('conciliacion_error', 255)->nullable();
                }
            });
        }

        if (Schema::hasTable('pagos')) {
            Schema::table('pagos', function (Blueprint $table) {
                if (! Schema::hasColumn('pagos', 'monto_confirmado_e2')) {
                    $table->unsignedBigInteger('monto_confirmado_e2')->nullable();
                }
                if (! Schema::hasColumn('pagos', 'confirmado_por')) {
                    $table->unsignedBigInteger('confirmado_por')->nullable();
                }
            });
        }

        if (! Schema::hasTable('pago_transacciones')) {
            Schema::create('pago_transacciones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pago_id');
                $table->unsignedBigInteger('cuenta_bancaria_id')->nullable();
                $table->string('referencia', 40);                          // la que da el banco del pagador
                $table->string('tipo', 3)->default('PM');                   // PM (pago móvil) | TRF (transferencia)
                $table->unsignedBigInteger('monto_e2');                     // en `moneda`
                $table->string('moneda', 3)->default('VES');
                $table->string('estado', 15)->default('pendiente');         // pendiente | aprobada | rechazada | no_encontrada
                $table->string('origen', 12)->default('paciente');          // paciente | medico | sistema
                $table->json('respuesta')->nullable();                      // respuesta saneada del banco
                $table->string('mensaje', 255)->nullable();                 // el motivo, para la pantalla
                $table->timestamp('confirmada_el')->nullable();
                $table->timestamps();

                $table->foreign('pago_id')->references('id')->on('pagos')->cascadeOnDelete();
                $table->foreign('cuenta_bancaria_id')->references('id')->on('cuentas_bancarias')->nullOnDelete();

                // Una transacción bancaria se acredita una sola vez por cuenta: si el paciente vuelve a
                // declarar la misma referencia, no se suma otra vez.
                $table->unique(['cuenta_bancaria_id', 'referencia'], 'pago_transacciones_referencia_uq');
                $table->index(['pago_id', 'estado'], 'pago_transacciones_pago_estado_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_transacciones');

        if (Schema::hasTable('pagos')) {
            Schema::table('pagos', function (Blueprint $table) {
                if (Schema::hasColumn('pagos', 'monto_confirmado_e2')) {
                    $table->dropColumn('monto_confirmado_e2');
                }
                if (Schema::hasColumn('pagos', 'confirmado_por')) {
                    $table->dropColumn('confirmado_por');
                }
            });
        }

        if (Schema::hasTable('cuentas_bancarias')) {
            Schema::table('cuentas_bancarias', function (Blueprint $table) {
                foreach (['conciliacion', 'credenciales', 'conciliacion_verificada_el', 'conciliacion_error'] as $columna) {
                    if (Schema::hasColumn('cuentas_bancarias', $columna)) {
                        $table->dropColumn($columna);
                    }
                }
            });
        }
    }
};
