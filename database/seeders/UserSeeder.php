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
            'Root',
        ];

        // 2. Crear los roles si no existen
        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // 3. Crear un usuario para cada rol disponible
        $usersData = [
            [
                'name'     => 'Usuario Root',
                'email'    => 'admin@gmail.com',
                'password' => Hash::make('12345678'),
                'role'     => 'Root',
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