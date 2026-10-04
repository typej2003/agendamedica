<?php

namespace Database\Seeders;

use App\Models\Vademecum;
use Illuminate\Database\Seeder;

/**
 * Vademécum sintético para el médico de prueba (`gineco-00001`), con los mismos `codemedicina` que usan
 * los récipes de `FakeClinicalDataSeeder` — así el nombre del medicamento se puede resolver por el
 * catálogo, como pasa con los datos reales del legado.
 *
 * Idempotente (`updateOrCreate`): se puede correr solo sobre una base ya sembrada
 * (`php artisan db:seed --class=VademecumDePruebaSeeder`).
 */
class VademecumDePruebaSeeder extends Seeder
{
    private const REG_MEDICO = 'gineco-00001';

    public function run(): void
    {
        $medicamentos = [
            ['MED001', 'ACIDO FOLICO', 'FOLIFER', 'Tabletas 5 mg', 'Tomar 1 tableta vía oral cada 24 horas'],
            ['MED002', 'AMOXICILINA', 'AMOXAL', 'Cápsulas 500 mg', 'Tomar 1 cápsula vía oral cada 8 horas por 7 días'],
            ['MED003', 'IBUPROFENO', 'BRUFEN', 'Tabletas 400 mg', 'Tomar 1 tableta vía oral cada 8 horas si hay dolor'],
            ['MED004', 'COMPLEJO B', 'BEDOYECTA', 'Tabletas', 'Tomar 1 tableta vía oral cada 24 horas por 30 días'],
            ['MED005', 'METRONIDAZOL', 'FLAGYL', 'Tabletas 500 mg', 'Tomar 1 tableta vía oral cada 12 horas por 7 días'],
            ['MED006', 'SULFATO FERROSO', 'FERRANIN', 'Tabletas 300 mg', 'Tomar 1 tableta vía oral cada 24 horas con las comidas'],
        ];

        foreach ($medicamentos as [$codigo, $generico, $comercial, $presentacion, $uso]) {
            Vademecum::updateOrCreate(
                ['reg_medico' => self::REG_MEDICO, 'codemedicina' => $codigo],
                [
                    'nombregenerico' => $generico,
                    'nombrecomercial' => $comercial,
                    'presentacion' => $presentacion,
                    'uso' => $uso,
                ],
            );
        }
    }
}
