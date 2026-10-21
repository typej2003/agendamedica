<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El `operation_secret` de SyPago** (`pagos.operation_secret`).
 *
 * SyPago devuelve, al iniciar una operación asíncrona, un `transaction_id` y un `operation_secret`
 * (UUID). Ese secreto es parte de la cadena que SyPago firma en el webhook:
 * `"{payload crudo}.{nonce}.{operation_secret}"`. Sin guardarlo no se puede verificar la firma
 * ECDSA de la notificación.
 *
 * Se guarda con la respuesta de `request/otp` o `transaction/otp`; si el webhook llega antes de que
 * esa respuesta se persista, se procesa igual con la reconsulta a SyPago (el payload no se cree).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('operation_secret', 64)->nullable()->after('referencia_externa');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('operation_secret');
        });
    }
};
