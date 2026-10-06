<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Catálogos y roles: es lo único que una base de producción necesita sembrado.
        $this->call([
            RoleAndUserSeeder::class,
            CountrySeeder::class,
            StateSeeder::class,
            CitySeeder::class,
            SpecialtySeeder::class,
        ]);

        // Cuentas con clave conocida, centros y pacientes inventados: solo para desarrollo y pruebas.
        // En producción el primer administrador se crea a mano (`php artisan cuentas:admin`).
        if (app()->environment('production')) {
            return;
        }

        $this->call([
            UserSeeder::class,
            MedicalDataSeeder::class,
            FakeClinicalDataSeeder::class,
        ]);
    }
}