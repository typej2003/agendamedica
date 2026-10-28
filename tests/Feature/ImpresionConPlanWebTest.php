<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Services\ServicioService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * El corte de impresión por plan (2026-10-28): sin plan, vencido o sin la característica de impresión
 * no se abren las hojas; en gracia sí, igual que la sincronización.
 */
class ImpresionConPlanWebTest extends TestCase
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

    private function cancelarServicio(string $reg): void
    {
        RegMedicoServicio::where('reg_medico', $reg)->update(['estado' => RegMedicoServicio::CANCELADO]);
    }

    private function vencerEl(string $reg, string $fecha): void
    {
        RegMedicoServicio::where('reg_medico', $reg)->update(['vence_el' => $fecha]);
    }

    private function como($user, $medico, $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    public function test_con_plan_vigente_la_hoja_se_abre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->como($user, $medico, $gineco)->get(route('clinica.agenda.imprimir'))->assertOk();
        $this->como($user, $medico, $gineco)->get(route('clinica.formato.en-blanco', 'recipe'))->assertOk();
    }

    public function test_sin_servicio_no_se_imprime_y_manda_a_mi_plan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->cancelarServicio($medico->reg_medico);

        $this->como($user, $medico, $gineco)
            ->get(route('clinica.agenda.imprimir'))
            ->assertRedirect(route('clinica.plan'))
            ->assertSessionHas('error');
    }

    public function test_vencido_no_se_imprime_y_en_gracia_si(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->vencerEl($medico->reg_medico, '2026-10-05'); // hoy 2026-10-07: dentro de la gracia
        $this->como($user, $medico, $gineco)->get(route('clinica.agenda.imprimir'))->assertOk();

        $this->vencerEl($medico->reg_medico, '2026-09-20'); // fuera de la gracia
        $this->como($user, $medico, $gineco)
            ->get(route('clinica.agenda.imprimir'))
            ->assertRedirect(route('clinica.plan'))
            ->assertSessionHas('error');
    }

    public function test_un_plan_sin_la_caracteristica_de_impresion_no_imprime(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $plan = Plan::create([
            'codigo' => 'sin-impresion-web-t', 'nombre' => 'Sin impresión', 'frecuencia' => 'mensual',
            'precio_usd' => 10, 'caracteristicas' => ['impresion_reportes' => false],
        ]);
        app(ServicioService::class)->reemplazar($medico->reg_medico, $plan, 0, 'prueba');

        $this->como($user, $medico, $gineco)
            ->get(route('clinica.agenda.imprimir'))
            ->assertRedirect(route('clinica.plan'))
            ->assertSessionHas('error');
    }

    public function test_la_secretaria_sin_plan_vuelve_al_inicio_con_el_aviso(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->cancelarServicio($medico->reg_medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get(route('clinica.agenda.imprimir'))
            ->assertRedirect(route('clinica.inicio'))
            ->assertSessionHas('error');
    }

    public function test_el_layout_muestra_el_aviso_sin_impresion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->cancelarServicio($medico->reg_medico);

        $this->como($user, $medico, $gineco)
            ->get(route('clinica.agenda'))
            ->assertOk()
            ->assertSee('data-plan-sin-impresion', false)
            ->assertSee('no se pueden imprimir reportes ni generar PDF');
    }

    public function test_el_boton_de_imprimir_se_reemplaza_si_el_plan_no_deja(): void
    {
        view()->share('planPermiteImprimir', false);
        view()->share('motivoSinImpresion', 'Tu plan no permite imprimir.');

        $html = Blade::render('<x-ui.imprimir-guardando url="/x" form="f" documento="d">Imprimir</x-ui.imprimir-guardando>');

        $this->assertStringContainsString('Mejora tu plan para imprimir', $html);
        $this->assertStringNotContainsString('Visualizar', $html);
    }

    public function test_todas_las_rutas_de_impresion_llevan_el_middleware(): void
    {
        $nombres = [
            'clinica.pacientes.historia.imprimir',
            'clinica.pacientes.crecimiento.imprimir',
            'clinica.pacientes.historia-pediatrica',
            'clinica.pacientes.vacunas.carnet',
            'clinica.consulta.recipes.imprimir',
            'clinica.consulta.recipe-pareja.imprimir',
            'clinica.consulta.documentos.imprimir',
            'clinica.agenda.imprimir',
            'clinica.agenda.documentos.imprimir',
            'clinica.listados.imprimir',
            'clinica.formato.en-blanco',
        ];

        foreach ($nombres as $nombre) {
            $ruta = Route::getRoutes()->getByName($nombre);
            $this->assertNotNull($ruta, "Falta la ruta {$nombre}.");
            $this->assertContains('clinica.plan.impresion', $ruta->gatherMiddleware(), "La ruta {$nombre} no tiene el corte de impresión.");
        }
    }
}
