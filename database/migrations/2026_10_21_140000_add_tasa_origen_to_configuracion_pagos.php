<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **De dónde sale la tasa VES/USD** (`configuracion_pagos.tasa_origen`): `manual` (la carga el
 * administrador) o `bcv` (la sincroniza el comando `tasas:bcv` desde la página del Banco Central).
 *
 * Nace en `bcv` porque el objetivo es que la tasa se mantenga sola; el administrador puede pasarla a
 * `manual` si necesita fijar un valor y que la sincronización no lo pise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_pagos', function (Blueprint $table) {
            $table->string('tasa_origen', 10)->default('bcv')->after('moneda_liquidacion');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_pagos', function (Blueprint $table) {
            $table->dropColumn('tasa_origen');
        });
    }
};
