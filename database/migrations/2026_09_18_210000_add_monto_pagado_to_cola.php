<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cola.monto_pagado` — cuánto se ha cobrado ya de esta cita.
 *
 * No es un booleano "pagada" a propósito: es usual que el paciente **abone** y el médico le
 * acepte el pago parcial, así que el estado de pago sale de comparar `monto` (lo que cuesta)
 * contra `monto_pagado` (lo que entregó). Es también lo que va a permitir, más adelante, que el
 * mensaje post-cita se convierta en recordatorio de cobro.
 *
 * `cola` es una tabla legada y este repo no la crea sola (la base real ya trae datos del
 * PowerBuilder), por eso el guard: contra el desplegado esto es un ALTER que suma una columna
 * nullable — los INSERT del legado no la mencionan y siguen funcionando igual.
 */
class AddMontoPagadoToCola extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('cola', 'monto_pagado')) {
            return;
        }

        Schema::table('cola', function (Blueprint $table) {
            $table->double('monto_pagado')->nullable()->after('monto');
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('cola', 'monto_pagado')) {
            return;
        }

        Schema::table('cola', function (Blueprint $table) {
            $table->dropColumn('monto_pagado');
        });
    }
}
