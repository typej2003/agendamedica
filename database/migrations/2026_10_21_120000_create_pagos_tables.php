<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El dominio de pagos de AppDDR** (2026-10-21): métodos de pago de la plataforma y de cada médico,
 * pagos, comisiones y liquidaciones.
 *
 * Dos carriles con el mismo motor:
 *  - **Planes**: el médico paga su plan a Doctorisimo (la plataforma recauda para sí).
 *  - **Consultas**: el paciente le paga al médico. Puede cobrar con su propio método (la plata va
 *    directo a él) o con un método de la plataforma (la plataforma recauda, descuenta comisión y le
 *    liquida al médico).
 *
 * **Dinero en centavos, siempre entero** (decisión del usuario, 2026-10-21): las columnas de montos
 * llevan el sufijo `_e2` y son `BIGINT`; los porcentajes son puntos base (`_bp`, 10000 = 100%) y las
 * tasas son `DECIMAL(18,6)` porque son un factor, no un monto (la cuenta se hace con un entero
 * escalado, ver `App\Pagos\Tasa`). No se usan `float` en ninguna cuenta de dinero.
 *
 * **Tablas nuevas de AppDDR, sólo del servidor**: no están en la lista blanca del sync del escritorio
 * (`config/sync_legado.php`) ni bajan al móvil. No se toca ninguna tabla existente.
 *
 * Las tablas de **comisiones y liquidaciones** se crean ya aunque el primer objetivo sea sólo cobrar
 * los planes: el esquema queda preparado para las olas siguientes sin migrar pagos históricos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La cuenta bancaria de la plataforma o de un médico: la que recibe el cobro (SyPago) y la que
        // se usa para pagarle al médico en la liquidación.
        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->string('propietario', 10);                       // plataforma | medico
            $table->string('reg_medico', 50)->nullable();            // tenant, sólo si es de un médico
            $table->string('moneda', 3)->default('VES');
            $table->string('tipo', 10)->default('cuenta');           // cuenta | pago_movil
            $table->string('banco_codigo', 4);                       // código de 4 dígitos de SyPago
            $table->string('numero', 30)->nullable();                // cuenta (si es cuenta)
            $table->string('telefono', 20)->nullable();              // pago móvil (si es pago móvil)
            $table->string('titular', 120);
            $table->string('documento_tipo', 2);                     // V, E, J, G, P
            $table->string('documento_numero', 20);
            $table->boolean('predeterminada')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamp('verificada_el')->nullable();
            $table->timestamps();

            $table->index(['propietario', 'reg_medico', 'activa'], 'cuentas_bancarias_dueno_idx');
        });

        // Un método de pago operable: no es "la pasarela", es una fila con su configuración y su
        // credencial. `plataforma` es de Doctorisimo; `medico` es del consultorio.
        Schema::create('metodos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('propietario', 10);                       // plataforma | medico
            $table->string('reg_medico', 50)->nullable();
            $table->string('pasarela', 20);                          // sypago | manual
            $table->string('variante', 30);                          // debito_inmediato | pago_movil | transferencia
            $table->string('nombre', 100);                           // lo que se muestra
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->string('entorno', 10)->default('produccion');    // produccion | pruebas
            // Credenciales del driver, cifradas con APP_KEY (nunca en claro ni en los logs).
            $table->text('credenciales')->nullable();
            // Datos no secretos del método (subproducto de SyPago, etc.).
            $table->json('datos')->nullable();
            // Cuenta que recibe el dinero (la del comercio en SyPago).
            $table->unsignedBigInteger('cuenta_bancaria_id')->nullable();
            // Comisión por prestar este método (puntos base). Null = la regla global.
            $table->unsignedSmallInteger('comision_bp')->nullable();
            // planes | cobros | ambos: para qué se puede ofrecer.
            $table->string('disponible_para', 10)->default('ambos');
            // Token secreto del webhook de esta configuración (se genera solo).
            $table->string('webhook_token', 64)->unique()->nullable();
            $table->timestamp('verificado_el')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();

            $table->foreign('cuenta_bancaria_id')->references('id')->on('cuentas_bancarias')->nullOnDelete();
            $table->index(['propietario', 'reg_medico', 'activo'], 'metodos_pago_dueno_idx');
        });

        // Historial de la tasa VES/USD. La que se usa es la última con `vigente_desde <= ahora`.
        Schema::create('tasas_de_cambio', function (Blueprint $table) {
            $table->id();
            $table->string('moneda_origen', 3)->default('USD');
            $table->string('moneda_destino', 3)->default('VES');
            $table->decimal('tasa', 18, 6);                          // factor: VES por 1 USD
            $table->string('origen', 10)->default('manual');         // manual | bcv
            $table->dateTime('vigente_desde');
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();

            $table->index(['moneda_origen', 'moneda_destino', 'vigente_desde'], 'tasas_cambio_vigencia_idx');
        });

        // Una sola fila: la política de la plataforma.
        Schema::create('configuracion_pagos', function (Blueprint $table) {
            $table->id();
            $table->string('moneda_plataforma', 3)->default('USD');
            $table->string('moneda_liquidacion', 3)->default('VES');
            $table->unsignedTinyInteger('liquidacion_dias')->default(1);        // T+1
            $table->unsignedBigInteger('liquidacion_minima_usd_e2')->default(0);
            $table->unsignedSmallInteger('vigencia_tasa_minutos')->default(15);
            $table->boolean('aplica_comision_metodo_en_citas_online')->default(false);
            $table->timestamps();
        });

        // Reglas de comisión con historia. Resolución: médico+método > médico > método > global.
        Schema::create('reglas_de_comision', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);                              // metodo_pago | cita_online
            $table->string('ambito', 10)->default('global');         // global | medico | metodo | plan
            $table->string('reg_medico', 50)->nullable();
            $table->unsignedBigInteger('metodo_pago_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedSmallInteger('porcentaje_bp');           // 10000 = 100%
            $table->dateTime('vigente_desde');
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();

            $table->foreign('metodo_pago_id')->references('id')->on('metodos_pago')->nullOnDelete();
            $table->foreign('plan_id')->references('id')->on('planes')->nullOnDelete();
            $table->index(['tipo', 'activo', 'vigente_desde'], 'reglas_comision_vigencia_idx');
        });

        // El pago: intención y transacción. `beneficiario` es a quién le corresponde la plata;
        // `recaudador` es quién la recibió de verdad.
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->string('referencia', 40)->unique();              // ULID; viaja como internal_id a SyPago
            $table->string('token_publico', 64)->unique()->nullable(); // link /pagar/{token}
            $table->string('beneficiario_tipo', 10);                 // plataforma | medico
            $table->string('beneficiario_reg_medico', 50)->nullable();
            $table->string('recaudador', 10);                        // plataforma | medico
            $table->string('canal', 20);                             // plan | link | reserva_online | mostrador
            $table->string('pagable_tipo', 20);                      // plan | cita | prefactura
            $table->unsignedBigInteger('pagable_id')->nullable();
            $table->string('concepto', 150);
            $table->unsignedBigInteger('metodo_pago_id')->nullable();
            $table->unsignedBigInteger('cuenta_bancaria_id')->nullable();

            $table->string('moneda', 3);                             // moneda del cobro
            $table->unsignedBigInteger('monto_e2');                  // en `moneda`
            $table->unsignedBigInteger('monto_usd_e2');              // equivalente en USD
            $table->decimal('tasa', 18, 6)->nullable();              // VES por USD del momento

            // Comisiones calculadas (cascada: método sobre el bruto, cita online sobre el remanente).
            $table->unsignedBigInteger('comision_metodo_usd_e2')->default(0);
            $table->unsignedBigInteger('comision_cita_online_usd_e2')->default(0);
            $table->unsignedBigInteger('comision_total_usd_e2')->default(0);
            $table->unsignedBigInteger('neto_usd_e2')->default(0);
            $table->unsignedBigInteger('comision_total_ves_e2')->default(0);
            $table->unsignedBigInteger('neto_ves_e2')->default(0);

            $table->string('estado', 15)->default('pendiente');      // pendiente | en_proceso | aprobado | rechazado | cancelado | expirado | reembolsado
            $table->string('referencia_externa', 100)->nullable();   // transaction_id / ref_ibp
            $table->json('datos_pagador')->nullable();               // banco, documento, teléfono, nombre
            $table->json('respuesta')->nullable();                   // respuesta saneada del driver
            $table->timestamp('otp_enviado_el')->nullable();
            $table->unsignedTinyInteger('otp_intentos')->default(0);
            $table->timestamp('pagado_el')->nullable();
            $table->timestamp('expira_el')->nullable();
            $table->date('disponible_el')->nullable();               // T+1 para liquidar
            $table->string('estado_liquidacion', 15)->default('no_aplica'); // no_aplica | pendiente | liquidado
            $table->unsignedBigInteger('liquidacion_id')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();

            $table->foreign('metodo_pago_id')->references('id')->on('metodos_pago')->nullOnDelete();
            $table->foreign('cuenta_bancaria_id')->references('id')->on('cuentas_bancarias')->nullOnDelete();
            $table->index(['estado', 'expira_el'], 'pagos_estado_expira_idx');
            $table->index(['pagable_tipo', 'pagable_id'], 'pagos_pagable_idx');
            $table->index(['beneficiario_reg_medico', 'estado'], 'pagos_beneficiario_idx');
            $table->index(['canal', 'estado'], 'pagos_canal_idx');
        });

        // El desglose de comisiones de un pago, con el orden de la cascada y la base de cada paso.
        Schema::create('pago_comisiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pago_id');
            $table->unsignedTinyInteger('secuencia');                 // 1 = método, 2 = cita online
            $table->string('tipo', 20);                              // metodo_pago | cita_online | plan | ajuste
            $table->unsignedBigInteger('base_usd_e2');
            $table->unsignedBigInteger('base_ves_e2')->default(0);
            $table->unsignedSmallInteger('porcentaje_bp');
            $table->unsignedBigInteger('monto_usd_e2');
            $table->unsignedBigInteger('monto_ves_e2')->default(0);
            $table->unsignedBigInteger('regla_id')->nullable();
            $table->string('detalle', 200)->nullable();
            $table->timestamps();

            $table->foreign('pago_id')->references('id')->on('pagos')->cascadeOnDelete();
            $table->foreign('regla_id')->references('id')->on('reglas_de_comision')->nullOnDelete();
            $table->index(['pago_id', 'secuencia'], 'pago_comisiones_pago_idx');
        });

        // Bitácora de lo que pasó con el pago (webhook, consulta, acción manual).
        Schema::create('pagos_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pago_id');
            $table->string('origen', 15);                            // webhook | consulta | manual | sistema
            $table->string('evento', 60);
            $table->json('payload')->nullable();
            $table->string('estado_anterior', 15)->nullable();
            $table->string('estado_nuevo', 15)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->foreign('pago_id')->references('id')->on('pagos')->cascadeOnDelete();
            $table->index(['pago_id', 'id'], 'pagos_eventos_pago_idx');
        });

        // Liquidación T+1 por médico (futuro; el MVP sólo cobra planes).
        Schema::create('liquidaciones', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 50);
            $table->date('periodo_desde');
            $table->date('periodo_hasta');
            $table->unsignedBigInteger('total_bruto_usd_e2')->default(0);
            $table->unsignedBigInteger('total_comisiones_usd_e2')->default(0);
            $table->unsignedBigInteger('total_neto_usd_e2')->default(0);
            $table->decimal('tasa', 18, 6)->nullable();
            $table->unsignedBigInteger('total_neto_ves_e2')->default(0);
            $table->string('moneda', 3)->default('VES');
            $table->string('estado', 12)->default('borrador');       // borrador | confirmada | pagada | anulada
            $table->unsignedBigInteger('cuenta_bancaria_id')->nullable();
            $table->timestamp('pagada_el')->nullable();
            $table->string('referencia', 100)->nullable();
            $table->text('nota')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();

            $table->foreign('cuenta_bancaria_id')->references('id')->on('cuentas_bancarias')->nullOnDelete();
            $table->index(['reg_medico', 'estado', 'periodo_desde'], 'liquidaciones_medico_idx');
        });

        // Detalle congelado de una liquidación. `pago_id` único: un pago no entra en dos liquidaciones.
        Schema::create('liquidacion_pagos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('liquidacion_id');
            $table->unsignedBigInteger('pago_id')->unique();
            $table->unsignedBigInteger('bruto_usd_e2');
            $table->unsignedBigInteger('comisiones_usd_e2')->default(0);
            $table->unsignedBigInteger('neto_usd_e2');
            $table->unsignedBigInteger('neto_ves_e2')->default(0);
            $table->timestamps();

            $table->foreign('liquidacion_id')->references('id')->on('liquidaciones')->cascadeOnDelete();
            $table->foreign('pago_id')->references('id')->on('pagos')->restrictOnDelete();
        });

        // Libro mayor del saldo del médico (futuro). Entradas inmutables; el saldo es la suma.
        Schema::create('movimientos_de_saldo', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 50);
            $table->string('tipo', 10);                              // credito | debito | ajuste
            $table->string('concepto', 150);
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->unsignedBigInteger('liquidacion_id')->nullable();
            $table->bigInteger('monto_usd_e2');                      // con signo: los débitos restan
            $table->bigInteger('saldo_inicial_usd_e2')->nullable();
            $table->bigInteger('saldo_final_usd_e2')->nullable();
            $table->date('disponible_el')->nullable();
            $table->unsignedBigInteger('autor_id')->nullable();
            $table->text('nota')->nullable();
            $table->timestamps();

            $table->foreign('pago_id')->references('id')->on('pagos')->nullOnDelete();
            $table->foreign('liquidacion_id')->references('id')->on('liquidaciones')->nullOnDelete();
            $table->index(['reg_medico', 'disponible_el'], 'movimientos_saldo_medico_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_de_saldo');
        Schema::dropIfExists('liquidacion_pagos');
        Schema::dropIfExists('liquidaciones');
        Schema::dropIfExists('pagos_eventos');
        Schema::dropIfExists('pago_comisiones');
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('reglas_de_comision');
        Schema::dropIfExists('configuracion_pagos');
        Schema::dropIfExists('tasas_de_cambio');
        Schema::dropIfExists('metodos_pago');
        Schema::dropIfExists('cuentas_bancarias');
    }
};
