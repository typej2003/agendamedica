<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La sincronización automática de la tasa VES/USD** (`configuracion_pagos`).
 *
 *  - `tasa_automatica` (boolean, default `true`): la **preferencia** que enciende o apaga el comando
 *    `tasas:bcv`. Es un interruptor, no un origen: el origen de cada tasa (`manual` | `bcv`) sigue en
 *    `tasas_de_cambio.origen`.
 *  - `tasa_sincronizada_el`: cuándo fue el **último intento** de sincronización, haya cambiado la tasa o
 *    no.
 *  - `tasa_sincronizacion_estado` (`ok` | `error`) y `tasa_sincronizacion_error`: cómo terminó ese intento.
 *
 * La **fecha en que la tasa cambió por última vez** no se duplica acá: es el último registro de
 * `tasas_de_cambio` (el que está vigente). Así no hay dos fuentes que se puedan desincronizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_pagos', function (Blueprint $table) {
            $table->boolean('tasa_automatica')->default(true)->after('moneda_liquidacion');
            $table->timestamp('tasa_sincronizada_el')->nullable()->after('tasa_automatica');
            $table->string('tasa_sincronizacion_estado', 10)->nullable()->after('tasa_sincronizada_el');
            $table->text('tasa_sincronizacion_error')->nullable()->after('tasa_sincronizacion_estado');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_pagos', function (Blueprint $table) {
            $table->dropColumn([
                'tasa_automatica',
                'tasa_sincronizada_el',
                'tasa_sincronizacion_estado',
                'tasa_sincronizacion_error',
            ]);
        });
    }
};
