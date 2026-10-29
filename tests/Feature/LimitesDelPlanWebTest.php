<?php

namespace Tests\Feature;

use App\Models\MedicoRegistro;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\ServicioService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * Los cupos del plan en las pantallas del médico (2026-10-28): la secretaría que no entra pide mejorar el
 * plan, y la afiliación de un médico se rechaza cuando el consultorio ya está lleno.
 */
class LimitesDelPlanWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();
        parent::tearDown();
    }

    private function como(User $user, $medico, $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function plan(string $reg, string $codigo): void
    {
        app(ServicioService::class)->reemplazar($reg, Plan::porCodigo($codigo), 0, 'prueba');
    }

    public function test_con_una_secretaria_el_plan_de_uno_oculta_el_alta_y_pide_mejorar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->plan($medico->reg_medico, 'equipo-1');

        $this->como($user, $medico, $gineco)->post(route('clinica.usuarios.crear'), [
            'name' => 'Primera secretaria',
            'email' => 'primera-' . substr(uniqid(), -6) . '@example.com',
        ])->assertRedirect(route('clinica.usuarios'));

        $this->como($user, $medico, $gineco)
            ->get(route('clinica.usuarios'))
            ->assertOk()
            ->assertDontSee('Nueva secretaria')
            ->assertSee('Mejorar plan')
            ->assertSee('Tu plan incluye 1 secretaría');

        $this->como($user, $medico, $gineco)
            ->from(route('clinica.usuarios'))
            ->post(route('clinica.usuarios.crear'), [
                'name' => 'Segunda secretaria',
                'email' => 'segunda-' . substr(uniqid(), -6) . '@example.com',
            ])
            ->assertSessionHasErrors('secretaria');

        $this->assertSame(
            1,
            User::whereHas('roles', fn ($q) => $q->where('name', 'Secretaria'))
                ->whereIn('id', MedicoRegistro::where('reg_medico', $medico->reg_medico)->whereNotNull('user_id')->pluck('user_id'))
                ->count(),
            'No se creó una segunda cuenta.',
        );
    }

    public function test_afiliar_un_segundo_medico_se_rechaza_hasta_mejorar_el_plan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->plan($medico->reg_medico, 'equipo-1');

        $cuentas = app(CuentaService::class);

        try {
            $cuentas->asignarRegistro($otro, $medico->reg_medico);
            $this->fail('El plan de un médico no debía permitir afiliar a otro.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('regNuevo', $e->errors());
        }

        $this->assertFalse(MedicoRegistro::where('medico_id', $otro->id)->where('reg_medico', $medico->reg_medico)->exists());

        $this->plan($medico->reg_medico, 'equipo-2');
        $cuentas->asignarRegistro($otro, $medico->reg_medico);

        $this->assertTrue(MedicoRegistro::where('medico_id', $otro->id)->where('reg_medico', $medico->reg_medico)->exists());
        $this->assertSame(2, app(\App\Services\CupoDelPlan::class)->conteo($medico->reg_medico)['medicos']);
    }
}
