<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de gestión de cuentas en `users` (dashboard de administración, Fase 1).
 *
 *  - `tipo`: tipo PRINCIPAL de la cuenta (`administrador` | `medico` | `paciente`), o sea con qué entra a
 *    la app. Ser administrador NO es un tipo sino un rol Spatie (`Root`/`Administrador`): así un médico
 *    puede ser también administrador sin tener dos cuentas. Nullable a propósito: lo fija `CuentaService`
 *    al crear la cuenta; una fila creada por fuera (legado, seeders) queda en null en vez de mal clasificada.
 *  - `must_change_password`: la clave actual es temporal y hay que cambiarla en el primer inicio de sesión.
 *  - `is_active` / `blocked_reason`: bloqueo de la cuenta (a futuro, también por falta de pago).
 *
 * El vínculo con el doctor ya existe (`medicos.user_id`); no se duplica en `users`.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tipo', 20)->nullable()->after('password');
            $table->boolean('must_change_password')->default(false)->after('tipo');
            $table->boolean('is_active')->default(true)->after('must_change_password');
            $table->string('blocked_reason')->nullable()->after('is_active');
        });

        $this->enlazarMedicosPorCorreo();
        $this->rellenarTipo();
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'must_change_password', 'is_active', 'blocked_reason']);
        });
    }

    /**
     * Hasta ahora el app resolvía el médico de un usuario con `user_id = ? OR email = ?`. El código nuevo
     * usa solo `medicos.user_id`, así que se enlazan acá los médicos que dependían del correo.
     * Solo cuando el correo identifica a UN usuario sin ficha de médico y a UN médico sin usuario.
     */
    private function enlazarMedicosPorCorreo(): void
    {
        $medicos = DB::table('medicos')->whereNull('user_id')->whereNotNull('email')->get(['id', 'email']);

        foreach ($medicos as $medico) {
            $usuarios = DB::table('users')->where('email', $medico->email)->pluck('id');
            if ($usuarios->count() !== 1) {
                continue;
            }
            $yaTieneFicha = DB::table('medicos')->where('user_id', $usuarios->first())->exists();
            if (! $yaTieneFicha) {
                DB::table('medicos')->where('id', $medico->id)->update(['user_id' => $usuarios->first()]);
            }
        }
    }

    private function rellenarTipo(): void
    {
        // 1) Con ficha de médico.
        DB::table('users')
            ->whereIn('id', DB::table('medicos')->whereNotNull('user_id')->select('user_id'))
            ->update(['tipo' => 'medico']);

        // 2) Pacientes: por su ficha o por el rol.
        if (Schema::hasTable('pacientes') && Schema::hasColumn('pacientes', 'user_id')) {
            DB::table('users')
                ->whereNull('tipo')
                ->whereIn('id', DB::table('pacientes')->whereNotNull('user_id')->select('user_id'))
                ->update(['tipo' => 'paciente']);
        }

        if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
            $conRol = fn (array $roles) => DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->whereIn('roles.name', $roles)
                ->select('model_has_roles.model_id');

            DB::table('users')->whereNull('tipo')->whereIn('id', $conRol(['Paciente']))->update(['tipo' => 'paciente']);

            // 3) Lo que quede con rol de administración.
            DB::table('users')->whereNull('tipo')->whereIn('id', $conRol(['Root', 'Administrador']))->update(['tipo' => 'administrador']);
        }
    }
};
