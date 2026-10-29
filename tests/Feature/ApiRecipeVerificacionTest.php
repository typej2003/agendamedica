<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El QR de verificación del récipe desde el móvil: la ruta del API devuelve la URL y el código, y usa la
 * misma Action de la web, así que reimprimir devuelve el mismo token.
 */
class ApiRecipeVerificacionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-verif' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-verif' . substr(uniqid(), -6) . '@example.com',
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

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    private function consulta(Medico $medico, int $numhistoria = 900001, int $nroconsulta = 1): Consulta
    {
        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
        ]);
    }

    public function test_devuelve_la_url_y_el_codigo_y_reimprimir_da_el_mismo(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $primera = $this->postJson('/api/app/recipes/verificacion', [
            'numhistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'numero' => 1,
        ])->assertOk();

        $this->assertStringContainsString('/verificar/', $primera->json('url'));
        $this->assertNotEmpty($primera->json('codigo'));

        $segunda = $this->postJson('/api/app/recipes/verificacion', [
            'numhistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'numero' => 1,
        ])->assertOk();

        $this->assertSame($primera->json('url'), $segunda->json('url'));
        $this->assertSame($primera->json('codigo'), $segunda->json('codigo'));
    }

    public function test_una_consulta_de_otro_medico_da_404(): void
    {
        $ajeno = $this->medico(autenticar: false);
        $consulta = $this->consulta($ajeno, 900002, 1);
        $this->medico();

        $this->postJson('/api/app/recipes/verificacion', [
            'numhistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'numero' => 1,
        ])->assertNotFound();
    }

    public function test_valida_los_numeros(): void
    {
        $this->medico();

        $this->postJson('/api/app/recipes/verificacion', [
            'numhistoria' => 'no',
            'nroconsulta' => 1,
            'numero' => 0,
        ])->assertStatus(422);
    }
}
