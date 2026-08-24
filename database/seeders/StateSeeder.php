<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StateSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('estados')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $estados = [
            ['id' => 1, 'name' => 'Amazonas', 'iso_3166-2' => 'VE-X', 'country_id' => 237],
            ['id' => 2, 'name' => 'Anzoátegui', 'iso_3166-2' => 'VE-B', 'country_id' => 237],
            ['id' => 3, 'name' => 'Apure', 'iso_3166-2' => 'VE-C', 'country_id' => 237],
            ['id' => 4, 'name' => 'Aragua', 'iso_3166-2' => 'VE-D', 'country_id' => 237],
            ['id' => 5, 'name' => 'Barinas', 'iso_3166-2' => 'VE-E', 'country_id' => 237],
            ['id' => 6, 'name' => 'Bolívar', 'iso_3166-2' => 'VE-F', 'country_id' => 237],
            ['id' => 7, 'name' => 'Carabobo', 'iso_3166-2' => 'VE-G', 'country_id' => 237],
            ['id' => 8, 'name' => 'Cojedes', 'iso_3166-2' => 'VE-H', 'country_id' => 237],
            ['id' => 9, 'name' => 'Delta Amacuro', 'iso_3166-2' => 'VE-Y', 'country_id' => 237],
            ['id' => 10, 'name' => 'Falcón', 'iso_3166-2' => 'VE-I', 'country_id' => 237],
            ['id' => 11, 'name' => 'Guárico', 'iso_3166-2' => 'VE-J', 'country_id' => 237],
            ['id' => 12, 'name' => 'Lara', 'iso_3166-2' => 'VE-K', 'country_id' => 237],
            ['id' => 13, 'name' => 'Mérida', 'iso_3166-2' => 'VE-L', 'country_id' => 237],
            ['id' => 14, 'name' => 'Miranda', 'iso_3166-2' => 'VE-M', 'country_id' => 237],
            ['id' => 15, 'name' => 'Monagas', 'iso_3166-2' => 'VE-N', 'country_id' => 237],
            ['id' => 16, 'name' => 'Nueva Esparta', 'iso_3166-2' => 'VE-O', 'country_id' => 237],
            ['id' => 17, 'name' => 'Portuguesa', 'iso_3166-2' => 'VE-P', 'country_id' => 237],
            ['id' => 18, 'name' => 'Sucre', 'iso_3166-2' => 'VE-R', 'country_id' => 237],
            ['id' => 19, 'name' => 'Táchira', 'iso_3166-2' => 'VE-S', 'country_id' => 237],
            ['id' => 20, 'name' => 'Trujillo', 'iso_3166-2' => 'VE-T', 'country_id' => 237],
            ['id' => 21, 'name' => 'La Guaira', 'iso_3166-2' => 'VE-W', 'country_id' => 237],
            ['id' => 22, 'name' => 'Yaracuy', 'iso_3166-2' => 'VE-U', 'country_id' => 237],
            ['id' => 23, 'name' => 'Zulia', 'iso_3166-2' => 'VE-V', 'country_id' => 237],
            ['id' => 24, 'name' => 'Distrito Capital', 'iso_3166-2' => 'VE-A', 'country_id' => 237],
            ['id' => 25, 'name' => 'Dependencias Federales', 'iso_3166-2' => 'VE-Z', 'country_id' => 237],
        ];

        DB::table('estados')->insert($estados);
    }
}