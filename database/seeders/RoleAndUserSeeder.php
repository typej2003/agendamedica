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

        // 1. Crear los Roles
        $roleRoot = Role::create(['name' => 'Root']);
        $roleManagerPrincipal = Role::create(['name' => 'Manager Almacen Principal']);
        $roleManagerAlmacen = Role::create(['name' => 'Manager Almacen']);
        $roleManagerCaja = Role::create(['name' => 'Manager Caja']);
        $roleCajero = Role::create(['name' => 'Cajero']);
        $roleCliente = Role::create(['name' => 'Cliente']);
        $roleProveedor = Role::create(['name' => 'Proveedor']);

        // 2. Crear Usuario Root por Defecto
        $rootUser = User::create([
            'name' => 'Administrador Root',
            'email' => 'root@admin.com',
            'password' => Hash::make('12345678'),
        ]);

        // Asignar rol Root
        $rootUser->assignRole($roleRoot);
    }
}