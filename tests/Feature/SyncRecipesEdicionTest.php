<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Editar y eliminar un récipe **ya subido** desde el móvil: viaja como una creación de `recipes_ediciones` y
 * el servidor aplica `GuardarRecipe` o `EliminarRecipe`.
 */
class SyncRecipesEdicionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): Medico
    {
        $regMedico = 'test-rec' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-rec' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);
        Sanctum::actingAs($user, ['*'], 'api');

        return $medico;
    }

    private function consulta(Medico $medico): Consulta
    {
        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => 920001,
            'nroconsulta' => 1,
            'fecha' => '2026-10-01',
        ]);
    }

    private function recipe(Consulta $consulta): void
    {
        DB::table('recipe_detalle')->insert([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => 920001,
            'nroconsulta' => 1,
            'recipe' => 1,
            'fe_emision' => '2026-10-01',
            'fe_vence' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('recipes')->insert([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => 920001,
            'nroconsulta' => 1,
            'recipe' => 1,
            'codemedicina' => 'MED-1',
            'cantidad' => 1,
            'indicaciones' => 'Tomar uno al día.',
            'descripcion' => 'MEDICINA / 10 mg',
            'orden' => 1,
            'fecha' => '2026-10-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sync(array $change)
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => [$change]])->assertOk();
    }

    public function test_edita_un_recipe_ya_subido(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->recipe($consulta);

        $this->sync([
            'table' => 'recipes_ediciones',
            'operation' => 'created',
            'temp_id' => -80,
            'consulta_id' => $consulta->id,
            'numero' => 1,
            'accion' => 'editar',
            'items' => [
                ['codemedicina' => 'MED-1', 'cantidad' => 2, 'indicaciones' => 'Tomar dos al día.'],
            ],
        ]);

        $fila = DB::table('recipes')->where('nrohistoria', 920001)->first();
        $this->assertSame(2, (int) $fila->cantidad);
        $this->assertSame('Tomar dos al día.', $fila->indicaciones);
    }

    public function test_elimina_un_recipe_ya_subido(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->recipe($consulta);

        $this->sync([
            'table' => 'recipes_ediciones',
            'operation' => 'created',
            'temp_id' => -81,
            'consulta_id' => $consulta->id,
            'numero' => 1,
            'accion' => 'eliminar',
        ]);

        $this->assertSame(0, DB::table('recipes')->where('nrohistoria', 920001)->count());
        $this->assertSame(0, DB::table('recipe_detalle')->where('nrohistoria', 920001)->count());
    }
}
