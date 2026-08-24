<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Limpiar la caché de permisos de Spatie
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Definir los roles del sistema
        $roles = [
            'Administrador',
            'Supervisión',
            'Almacén',
            'Ventas',
            'Cliente',
        ];

        // 2. Crear los roles si no existen
        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // 3. Crear un usuario para cada rol disponible
        $usersData = [
            [
                'name'     => 'Usuario Administrador',
                'email'    => 'admin@admin.com',
                'password' => Hash::make('password'),
                'role'     => 'Administrador',
            ],
            [
                'name'     => 'Usuario Supervisor',
                'email'    => 'supervisor@admin.com',
                'password' => Hash::make('password'),
                'role'     => 'Supervisión',
            ],
            [
                'name'     => 'Usuario Almacén',
                'email'    => 'almacen@admin.com',
                'password' => Hash::make('password'),
                'role'     => 'Almacén',
            ],
            [
                'name'     => 'Usuario Ventas',
                'email'    => 'ventas@admin.com',
                'password' => Hash::make('password'),
                'role'     => 'Ventas',
            ],
            [
                'name'     => 'Usuario Cliente',
                'email'    => 'cliente@admin.com',
                'password' => Hash::make('password'),
                'role'     => 'Cliente',
            ],
        ];

        foreach ($usersData as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'     => $data['name'],
                    'password' => $data['password'],
                ]
            );

            // Asignar el rol al usuario
            $user->assignRole($data['role']);
        }
    }
}