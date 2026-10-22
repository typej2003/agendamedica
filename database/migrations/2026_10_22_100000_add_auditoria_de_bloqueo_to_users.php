<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.blocked_at` y `users.blocked_by`: cuándo y quién bloqueó la cuenta (la auditoría del bloqueo de
 * pacientes desde el panel). El bloqueo en sí sigue siendo `is_active` / `blocked_reason`. Nullable y aditivo.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('blocked_reason');
            $table->unsignedBigInteger('blocked_by')->nullable()->after('blocked_at');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_by']);
        });
    }
};
