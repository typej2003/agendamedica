<?php

namespace Database\Seeders;

use App\Models\Consulta;
use App\Models\Recipe;
use App\Models\RecipeDetalle;
use App\Models\RecipeGrupo;
use App\Models\RecipeGrupoDetalle;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Consultas, récipes y tratamientos sintéticos del médico de prueba (`gineco-00001`), **con la forma
 * de los datos reales del legado** (ROADMAP.md Paso 18.B):
 *
 * - `nroconsulta` correlativo **por historia** (1, 2, 3… de cada paciente), no por médico.
 * - Cada récipe cuelga de una consulta que existe, tiene de 1 a 3 medicamentos con el mismo número de
 *   `recipe` y su cabecera en `recipe_detalle`, y `descripcion` en NULL: el nombre sale del vademécum.
 * - Tres tratamientos (plantillas) con los códigos de `VademecumDePruebaSeeder`.
 *
 * Idempotente y **no destructivo para el resto de la base**: renumera las consultas que ya hay y
 * rehace solo los récipes y tratamientos de este médico. Se puede correr sobre una base ya sembrada
 * (`php artisan db:seed --class=RecipesDePruebaSeeder`).
 */
class RecipesDePruebaSeeder extends Seeder
{
    private const REG_MEDICO = 'gineco-00001';

    private const MEDICAMENTOS = [
        'MED001' => 'Tomar 1 tableta vía oral cada 24 horas',
        'MED002' => 'Tomar 1 cápsula vía oral cada 8 horas por 7 días',
        'MED003' => 'Tomar 1 tableta vía oral cada 8 horas si hay dolor',
        'MED004' => 'Tomar 1 tableta vía oral cada 24 horas por 30 días',
        'MED005' => 'Tomar 1 tableta vía oral cada 12 horas por 7 días',
        'MED006' => 'Tomar 1 tableta vía oral cada 24 horas con las comidas',
    ];

    public function run(): void
    {
        $faker = FakerFactory::create('es_VE');
        $faker->seed(18);

        DB::transaction(function () use ($faker) {
            $this->renumerarConsultas();
            $this->rehacerRecipes($faker);
            $this->rehacerTratamientos();
        });
    }

    private function renumerarConsultas(): void
    {
        $porHistoria = Consulta::where('reg_medico', self::REG_MEDICO)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->groupBy('numhistoria');

        foreach ($porHistoria as $consultas) {
            // En dos pasadas: la unicidad no existe hoy, pero un número temporal negativo evita
            // quedar a mitad de camino con dos consultas iguales si algún día se agrega.
            foreach ($consultas as $i => $consulta) {
                $consulta->update(['nroconsulta' => -($i + 1)]);
            }
            foreach ($consultas as $i => $consulta) {
                $consulta->update(['nroconsulta' => $i + 1]);
            }
        }
    }

    private function rehacerRecipes($faker): void
    {
        Recipe::where('reg_medico', self::REG_MEDICO)->delete();
        RecipeDetalle::where('reg_medico', self::REG_MEDICO)->delete();

        $consultas = Consulta::where('reg_medico', self::REG_MEDICO)->get();
        foreach ($consultas->random(min(40, $consultas->count())) as $consulta) {
            $codigos = $faker->randomElements(array_keys(self::MEDICAMENTOS), $faker->numberBetween(1, 3));

            RecipeDetalle::create([
                'reg_medico' => self::REG_MEDICO,
                'nrohistoria' => $consulta->numhistoria,
                'nroconsulta' => $consulta->nroconsulta,
                'recipe' => 1,
                'fe_emision' => $consulta->fecha,
                'fe_vence' => 30,
            ]);

            foreach (array_values($codigos) as $orden => $codigo) {
                Recipe::create([
                    'reg_medico' => self::REG_MEDICO,
                    'nrohistoria' => $consulta->numhistoria,
                    'nroconsulta' => $consulta->nroconsulta,
                    'codemedicina' => $codigo,
                    'descripcion' => null,
                    'indicaciones' => self::MEDICAMENTOS[$codigo],
                    'cantidad' => $faker->numberBetween(1, 3),
                    'orden' => $orden + 1,
                    'fecha' => $consulta->fecha,
                    'recipe' => 1,
                ]);
            }
        }
    }

    private function rehacerTratamientos(): void
    {
        RecipeGrupo::where('reg_medico', self::REG_MEDICO)->delete();
        RecipeGrupoDetalle::where('reg_medico', self::REG_MEDICO)->delete();

        $tratamientos = [
            '0001' => ['Control prenatal', ['MED001' => 1, 'MED006' => 1, 'MED004' => 1]],
            '0002' => ['Infección urinaria', ['MED002' => 2, 'MED003' => 1]],
            '0003' => ['Vaginosis bacteriana', ['MED005' => 1]],
        ];

        foreach ($tratamientos as $codigo => [$nombre, $medicamentos]) {
            RecipeGrupo::create(['reg_medico' => self::REG_MEDICO, 'codigo' => $codigo, 'tratamiento' => $nombre]);
            $orden = 1;
            foreach ($medicamentos as $codemedicina => $cantidad) {
                RecipeGrupoDetalle::create([
                    'reg_medico' => self::REG_MEDICO,
                    'codigo' => $codigo,
                    'codemedicina' => $codemedicina,
                    'indicaciones' => self::MEDICAMENTOS[$codemedicina],
                    'cantidad' => $cantidad,
                    'orden' => $orden++,
                ]);
            }
        }
    }
}
