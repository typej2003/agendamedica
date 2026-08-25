<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class RoleAndUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Crear o recuperar los Roles para el Área de Salud
        $roleRoot         = Role::firstOrCreate(['name' => 'Root', 'guard_name' => 'web']);
        $roleMedico       = Role::firstOrCreate(['name' => 'Medico', 'guard_name' => 'web']);
        $roleSecretaria   = Role::firstOrCreate(['name' => 'Secretaria', 'guard_name' => 'web']);
        $rolePaciente     = Role::firstOrCreate(['name' => 'Paciente', 'guard_name' => 'web']);
        $roleRepresentante = Role::firstOrCreate(['name' => 'Representante', 'guard_name' => 'web']);

        // 2. Crear o recuperar Usuario Root por Defecto
        $rootUser = User::firstOrCreate(
            ['email' => 'root@admin.com'],
            [
                'name' => 'Administrador Root',
                'password' => Hash::make('12345678'),
            ]
        );

        // Asignar rol Root al usuario si no lo tiene asignado
        if (!$rootUser->hasRole('Root')) {
            $rootUser->assignRole($roleRoot);
        }
    }
}