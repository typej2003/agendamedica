<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de gestión de cuentas en `users` (dashboard de usuarios y API keys).
 *
 *  - `must_change_password`: la clave actual es temporal y hay que cambiarla en el primer inicio de sesión.
 *  - `is_active` / `blocked_reason`: bloqueo de la cuenta (a futuro, también por falta de pago).
 *
 * No toca el esquema de acceso que ya existía: `users` (roles Spatie `Root`/`Administrador`/`Medico`…) sigue
 * siendo la tabla de acceso y `medicos.user_id` el vínculo con el doctor.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('must_change_password');
            $table->string('blocked_reason')->nullable()->after('is_active');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'is_active', 'blocked_reason']);
        });
    }
};
