<?php

namespace Database\Seeders;

use App\Actions\Catalogos\SembrarReferenciaDePediatria;
use Illuminate\Database\Seeder;

/**
 * **La referencia de pediatría** (WEB-12.0): las curvas de crecimiento y el catálogo inicial de vacunas, sin dueño. La regla (y de dónde salen los JSON) vive en
 * `App\Actions\Catalogos\SembrarReferenciaDePediatria`; este seeder nunca reemplaza un catálogo de vacunas que ya existe (para eso,
 * `php artisan pediatria:sembrar-referencia --reemplazar-vacunas`).
 *
 *   php artisan db:seed --class=ReferenciaPediatriaSeeder
 */
class ReferenciaPediatriaSeeder extends Seeder
{
    public function run(): void
    {
        app(SembrarReferenciaDePediatria::class)->ejecutar();
    }
}
