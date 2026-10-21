<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **El `internal_id` que viaja a SyPago** (`pagos.internal_id`).
 *
 * SyPago valida que `internal_id` tenga **35 caracteres o menos**; un UUID con guiones tiene 36 y la
 * confirmación del OTP falla con «The length of 'Internal Id' must be 35 characters or fewer». La
 * `referencia` interna sigue siendo el UUID (sirve para el correo y para la URL), y este campo es el que
 * se manda a SyPago: 32 caracteres hexadecimales, único.
 *
 * A los pagos que ya existían se les rellena quitándole los guiones a la `referencia` (32 caracteres),
 * así un pago pendiente creado antes de este cambio se puede confirmar sin recrearlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('internal_id', 35)->nullable()->unique()->after('referencia');
        });

        DB::table('pagos')->orderBy('id')->chunkById(100, function ($pagos) {
            foreach ($pagos as $pago) {
                $internalId = substr(str_replace('-', '', (string) $pago->referencia), 0, 35);

                if ($internalId !== '') {
                    DB::table('pagos')->where('id', $pago->id)->update(['internal_id' => $internalId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropUnique(['internal_id']);
            $table->dropColumn('internal_id');
        });
    }
};
