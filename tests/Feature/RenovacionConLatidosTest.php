<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Latidos\Latidos;
use App\Latidos\RenovacionAutomatica;
use App\Mail\PagoDePlanAprobado;
use App\Models\Latido;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **Renovación automática con Latidos** (fase 3, 2026-10-31).
 *
 * La regla: no se renueva antes del vencimiento; el día del vencimiento se descuentan los Latidos, el plan
 * se extiende desde el día siguiente y no se le pide nada a SyPago. Si no alcanzan, no se toca nada. Si el
 * comando llegó tarde, reintenta en gracia y el período nuevo empieza hoy.
 */
class RenovacionConLatidosTest extends TestCase
{
    use DatabaseTransactions;

    private function medicoDePrueba(): array
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $reg = 'ren-' . substr(uniqid(), -7);

        $user = User::create([
            'name' => 'Dr. Renovación', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. Renovación', 'lastname' => 'Latidos',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $gineco];
    }

    private function planDePrueba(): Plan
    {
        return Plan::create([
            'codigo' => 'ren-plan-' . substr(uniqid(), -6),
            'nombre' => 'Plan de renovación',
            'descripcion' => 'Para la suite',
            'frecuencia' => Plan::MENSUAL,
            'precio_usd' => 20,
            'restricciones' => null,
            'es_default' => false,
            'visible' => true,
            'activo' => true,
            'orden' => 10,
        ]);
    }

    /** Deja un servicio que vence exactamente en la fecha pedida. */
    private function servicioHasta(string $regMedico, Plan $plan, Carbon $vence): RegMedicoServicio
    {
        // El alta del médico le da una prueba gratuita por el evento; para estas pruebas manda el servicio
        // que fijamos acá, con su vencimiento exacto.
        RegMedicoServicio::where('reg_medico', $regMedico)->delete();

        return RegMedicoServicio::create([
            'reg_medico' => $regMedico,
            'plan_id' => $plan->id,
            'plan_nombre' => $plan->nombre,
            'origen' => RegMedicoServicio::ORIGEN_COMPRA,
            'inicia_el' => $vence->copy()->subMonthNoOverflow(),
            'vence_el' => $vence,
            'monto_usd' => 20,
            'restricciones' => $plan->restricciones?->toArray() ?? [],
            'caracteristicas' => $plan->caracteristicas?->toArray() ?? [],
            'estado' => RegMedicoServicio::ACTIVO,
        ]);
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id' => null,
        ]]);
    }

    private function acreditar(string $regMedico, int $latidos): void
    {
        app(Latidos::class)->acreditar($regMedico, $latidos, 'promocion', 'promo:' . $regMedico);
    }

    public function test_el_estado_marca_cubierta_y_silencia_el_aviso(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->servicioHasta($medico->reg_medico, $plan, FechaDelConsultorio::hoyComoFecha());
        $this->acreditar($medico->reg_medico, 2000);

        $estado = app(RenovacionAutomatica::class)->estado($medico->reg_medico);

        $this->assertTrue($estado['activa']);
        $this->assertTrue($estado['cubierta']);
        $this->assertFalse($estado['aviso_por_vencer']);
        $this->assertSame(2000, $estado['latidos_necesarios']);
        $this->assertSame('Plan de renovación', $estado['plan']);
    }

    public function test_si_no_alcanzan_los_latidos_el_aviso_sigue(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->servicioHasta($medico->reg_medico, $plan, FechaDelConsultorio::hoyComoFecha());
        $this->acreditar($medico->reg_medico, 500);

        $estado = app(RenovacionAutomatica::class)->estado($medico->reg_medico);

        $this->assertFalse($estado['cubierta']);
        $this->assertTrue($estado['aviso_por_vencer']);
        $this->assertSame('latidos_insuficientes', $estado['motivo']);
    }

    public function test_no_renueva_antes_del_vencimiento(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->servicioHasta($medico->reg_medico, $plan, FechaDelConsultorio::hoyComoFecha()->addDay());
        $this->acreditar($medico->reg_medico, 2000);

        $this->artisan('latidos:renovar-planes')->assertExitCode(0);

        $this->assertSame(0, Pago::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(2000, app(Latidos::class)->disponibles($medico->reg_medico));
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_renueva_el_dia_del_vencimiento_y_no_cobra(): void
    {
        Mail::fake();
        Http::fake();

        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $hoy = FechaDelConsultorio::hoyComoFecha();
        $this->servicioHasta($medico->reg_medico, $plan, $hoy);
        $this->acreditar($medico->reg_medico, 2000);

        $this->artisan('latidos:renovar-planes')->assertExitCode(0);

        $pago = Pago::where('reg_medico', $medico->reg_medico)->latest('id')->firstOrFail();

        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertSame(2000, (int) $pago->latidos_usados);
        $this->assertSame(2000, (int) $pago->descuento_usd_e2);
        $this->assertSame(0, (int) $pago->monto_pasarela_usd_e2);
        $this->assertSame(0, (int) $pago->monto_e2);
        $this->assertNull($pago->metodo_pago_id);
        $this->assertStringStartsWith('auto-', $pago->referencia);
        $this->assertSame(0, app(Latidos::class)->disponibles($medico->reg_medico));

        $nuevo = RegMedicoServicio::where('reg_medico', $medico->reg_medico)->latest('id')->firstOrFail();
        $this->assertSame($hoy->copy()->addDay()->toDateString(), $nuevo->inicia_el->toDateString());
        $this->assertSame($hoy->copy()->addDay()->addMonthNoOverflow()->toDateString(), $nuevo->vence_el->toDateString());

        Http::assertNothingSent();
        Mail::assertSent(PagoDePlanAprobado::class);
    }

    public function test_no_renueva_si_no_alcanzan(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $hoy = FechaDelConsultorio::hoyComoFecha();
        $this->servicioHasta($medico->reg_medico, $plan, $hoy);
        $this->acreditar($medico->reg_medico, 500);

        $this->artisan('latidos:renovar-planes')->assertExitCode(0);

        $this->assertSame(0, Pago::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(500, app(Latidos::class)->disponibles($medico->reg_medico));
    }

    public function test_es_idempotente_por_vencimiento(): void
    {
        Mail::fake();

        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->servicioHasta($medico->reg_medico, $plan, FechaDelConsultorio::hoyComoFecha());
        $this->acreditar($medico->reg_medico, 2000);

        $this->artisan('latidos:renovar-planes')->assertExitCode(0);
        $this->artisan('latidos:renovar-planes')->assertExitCode(0);

        $this->assertSame(1, Pago::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $medico->reg_medico)
            ->whereDate('inicia_el', FechaDelConsultorio::hoyComoFecha()->addDay()->toDateString())->count());
    }

    public function test_si_llego_tarde_reintenta_en_gracia_y_empieza_hoy(): void
    {
        Mail::fake();

        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $hoy = FechaDelConsultorio::hoyComoFecha();
        $this->servicioHasta($medico->reg_medico, $plan, $hoy->copy()->subDay());
        $this->acreditar($medico->reg_medico, 2000);

        $this->artisan('latidos:renovar-planes')->assertExitCode(0);

        $nuevo = RegMedicoServicio::where('reg_medico', $medico->reg_medico)->latest('id')->firstOrFail();
        $this->assertSame($hoy->toDateString(), $nuevo->inicia_el->toDateString());
        $this->assertSame(0, app(Latidos::class)->disponibles($medico->reg_medico));
    }

    public function test_se_puede_apagar_la_renovacion_automatica(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->servicioHasta($medico->reg_medico, $plan, FechaDelConsultorio::hoyComoFecha());
        $this->acreditar($medico->reg_medico, 2000);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.renovacion'), ['renovacion_automatica' => 0])
            ->assertRedirect(route('clinica.plan'));

        $this->assertFalse((bool) Latido::where('reg_medico', $medico->reg_medico)->firstOrFail()->renovacion_automatica);

        $estado = app(RenovacionAutomatica::class)->estado($medico->reg_medico);
        $this->assertFalse($estado['activa']);
        $this->assertFalse($estado['cubierta']);
        $this->assertTrue($estado['aviso_por_vencer']);
        $this->assertSame('apagada', $estado['motivo']);
    }

    public function test_la_pagina_del_plan_no_muestra_los_dias_cuando_esta_cubierta(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->servicioHasta($medico->reg_medico, $plan, FechaDelConsultorio::hoyComoFecha());
        $this->acreditar($medico->reg_medico, 2000);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.plan'))
            ->assertOk()
            ->assertSee('Se renueva solo el')
            ->assertDontSee('(0 días)');
    }

    public function test_el_api_manda_renovacion_cubierta_y_aviso_apagado(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->servicioHasta($medico->reg_medico, $plan, FechaDelConsultorio::hoyComoFecha());
        $this->acreditar($medico->reg_medico, 2000);
        Sanctum::actingAs($user, ['*'], 'api');

        $this->postJson('/api/app/sync-app-data', [])
            ->assertOk()
            ->assertJsonPath('servicio.renovacion_automatica', true)
            ->assertJsonPath('servicio.renovacion_cubierta', true)
            ->assertJsonPath('servicio.aviso_por_vencer', false);
    }
}
