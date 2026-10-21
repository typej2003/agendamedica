<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A qué consultorio pertenece el pago** (`pagos.reg_medico`).
 *
 * En un pago de plan, el beneficiario es la plataforma pero el que paga es un médico: sin esta columna no
 * se sabría a qué `reg_medico` renovarle el servicio. En un cobro de consulta, es el médico que recibe.
 * Es el tenant con el que se lee y se lista el pago; el `beneficiario` sigue diciendo a quién le
 * corresponde la plata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('reg_medico', 50)->nullable()->after('beneficiario_reg_medico');
            $table->index(['reg_medico', 'estado'], 'pagos_reg_medico_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropIndex('pagos_reg_medico_idx');
            $table->dropColumn('reg_medico');
        });
    }
};
