<?php

namespace Tests\Feature;

use App\Latidos\Latidos;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\RetencionDeLatidos;
use App\Models\Specialty;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Pagos\Cobros;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Pagar el plan con Latidos** (fase 2, 2026-10-31): descuento total y parcial, retención, aprobación,
 * rechazo y cambio de tasa.
 *
 * La regla que se fija: los Latidos se aplican **primero** y la pasarela sólo cobra la diferencia. Si
 * cubren el 100 %, el pago nace aprobado y no se llama a SyPago. El descuento no se vuelve a convertir
 * cuando cambia la tasa.
 */
class PagoConLatidosWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
    }

    private function medicoDePrueba(): array
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $reg = 'lat-' . substr(uniqid(), -7);

        $user = User::create([
            'name' => 'Dra. Latidos', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. Latidos', 'lastname' => 'Pagos',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id' => null,
        ]]);
    }

    private function planDePrueba(): Plan
    {
        return Plan::create([
            'codigo' => 'lat-plan-' . substr(uniqid(), -6),
            'nombre' => 'Plan con Latidos',
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

    private function metodoDePrueba(): MetodoPago
    {
        $cuenta = CuentaBancaria::create([
            'propietario' => CuentaBancaria::PLATAFORMA,
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::CUENTA,
            'banco_codigo' => '0172',
            'numero' => '01340123456789012345',
            'titular' => 'Doctorisimo, C.A.',
            'documento_tipo' => 'J',
            'documento_numero' => 'J-12345678-9',
            'predeterminada' => true,
            'activa' => true,
        ]);

        return MetodoPago::create([
            'propietario' => MetodoPago::PLATAFORMA,
            'pasarela' => 'sypago',
            'variante' => 'debito_inmediato',
            'nombre' => 'SyPago · Débito inmediato',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'credenciales' => [
                'access_token' => 'token-de-prueba',
                'host' => 'https://app.sypago.net:8086/api/v1/',
            ],
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::AMBOS,
            'orden' => 1,
        ]);
    }

    private function acreditar(string $regMedico, int $latidos): void
    {
        app(Latidos::class)->acreditar($regMedico, $latidos, 'promocion', 'promo:' . $regMedico);
    }

    private function pagoDe(string $regMedico): Pago
    {
        return Pago::where('reg_medico', $regMedico)->latest('id')->firstOrFail();
    }

    private function falsificarSyPago(string $transaccion, string $estado = 'ACCP'): void
    {
        Http::fake(function ($request) use ($transaccion, $estado) {
            if (str_contains($request->url(), 'transaction/' . $transaccion) || str_contains($request->url(), 'transaction/otp')) {
                return Http::response(['status' => $estado, 'transaction_id' => $transaccion], 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_los_latidos_cubren_el_plan_y_no_se_llama_a_sypago(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->acreditar($medico->reg_medico, 2000);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.plan.elegir', $plan))
            ->assertOk()
            ->assertSee('2.000 Latidos')
            ->assertSee('Usar mis Latidos y activar')
            ->assertDontSee('name="metodo_pago_id"', false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.comprar'), [
                'plan_id' => $plan->id,
                'frecuencia' => Plan::MENSUAL,
            ])
            ->assertRedirect();

        $pago = $this->pagoDe($medico->reg_medico);

        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertSame(2000, (int) $pago->latidos_usados);
        $this->assertSame(2000, (int) $pago->descuento_usd_e2);
        $this->assertSame(0, (int) $pago->monto_pasarela_usd_e2);
        $this->assertSame(0, (int) $pago->monto_e2);
        $this->assertNull($pago->metodo_pago_id);
        $this->assertNotNull($pago->latido_id);
        $this->assertSame(0, app(Latidos::class)->disponibles($medico->reg_medico));
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $medico->reg_medico)
            ->where('origen', RegMedicoServicio::ORIGEN_COMPRA)->count());

        Http::assertNothingSent();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.plan.pagar', $pago))
            ->assertOk()
            ->assertSee('Sin cobro')
            ->assertSee('Latidos');
    }

    public function test_el_pago_parcial_descuenta_los_latidos_y_cobra_la_diferencia(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->acreditar($medico->reg_medico, 500);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.plan.elegir', $plan))
            ->assertOk()
            ->assertSee('500 Latidos')
            ->assertSee('USD 15,00')
            ->assertSee('VES 547,50');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.comprar'), [
                'plan_id' => $plan->id,
                'metodo_pago_id' => $metodo->id,
                'frecuencia' => Plan::MENSUAL,
            ])
            ->assertRedirect();

        $pago = $this->pagoDe($medico->reg_medico);

        $this->assertSame(Pago::PENDIENTE, $pago->estado);
        $this->assertSame(500, (int) $pago->latidos_usados);
        $this->assertSame(500, (int) $pago->descuento_usd_e2);
        $this->assertSame(1500, (int) $pago->monto_pasarela_usd_e2);
        $this->assertSame(54750, (int) $pago->monto_e2);
        $this->assertSame('36.500000', (string) $pago->tasa);
        $this->assertSame(500, app(Latidos::class)->disponibles($medico->reg_medico));

        $retencion = RetencionDeLatidos::where('pago_id', $pago->id)->firstOrFail();
        $this->assertSame(RetencionDeLatidos::ACTIVA, $retencion->estado);
        $this->assertSame(500, (int) $retencion->latidos);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.plan.pagar', $pago))
            ->assertOk()
            ->assertSee('500 Latidos')
            ->assertSee('VES 547,50')
            ->assertSee('USD 15,00');
    }

    public function test_aprobar_el_pago_parcial_descuenta_los_latidos_una_sola_vez(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->acreditar($medico->reg_medico, 500);
        $this->falsificarSyPago('tx-lat');

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);
        $pago->update([
            'estado' => Pago::EN_PROCESO,
            'referencia_externa' => 'tx-lat',
            'otp_enviado_el' => now(),
        ]);

        $resultado = app(Cobros::class)->conciliar($pago);

        $this->assertTrue($resultado->ok);
        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(0, app(Latidos::class)->disponibles($medico->reg_medico));
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $medico->reg_medico)
            ->where('origen', RegMedicoServicio::ORIGEN_COMPRA)->count());
        $this->assertSame(RetencionDeLatidos::CONSUMIDA, RetencionDeLatidos::where('pago_id', $pago->id)->firstOrFail()->estado);

        // El webhook/sondeo repetido no vuelve a descontar ni a renovar.
        app(Cobros::class)->conciliar($pago);
        $this->assertSame(0, app(Latidos::class)->disponibles($medico->reg_medico));
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $medico->reg_medico)
            ->where('origen', RegMedicoServicio::ORIGEN_COMPRA)->count());
    }

    public function test_un_pago_rechazado_libera_los_latidos(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->acreditar($medico->reg_medico, 500);
        $this->falsificarSyPago('tx-rechazo', 'RJCT');

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);
        $pago->update([
            'estado' => Pago::EN_PROCESO,
            'referencia_externa' => 'tx-rechazo',
            'otp_enviado_el' => now(),
        ]);

        $resultado = app(Cobros::class)->conciliar($pago);

        $this->assertFalse($resultado->ok);
        $this->assertSame(Pago::RECHAZADO, $pago->fresh()->estado);
        $this->assertSame(500, app(Latidos::class)->disponibles($medico->reg_medico));
        $this->assertSame(500, app(Latidos::class)->disponiblesParaUsar($medico->reg_medico));
        $this->assertSame(RetencionDeLatidos::LIBERADA, RetencionDeLatidos::where('pago_id', $pago->id)->firstOrFail()->estado);
        $this->assertSame(0, RegMedicoServicio::where('reg_medico', $medico->reg_medico)
            ->where('origen', RegMedicoServicio::ORIGEN_COMPRA)->count());
    }

    public function test_el_comando_libera_los_latidos_de_un_pago_vencido(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->acreditar($medico->reg_medico, 500);

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);
        $pago->update(['expira_el' => now()->subMinute()]);

        $this->artisan('pagos:conciliar')->assertExitCode(0);

        $this->assertSame(Pago::EXPIRADO, $pago->fresh()->estado);
        $this->assertSame(500, app(Latidos::class)->disponiblesParaUsar($medico->reg_medico));
        $this->assertSame(RetencionDeLatidos::LIBERADA, RetencionDeLatidos::where('pago_id', $pago->id)->firstOrFail()->estado);
    }

    public function test_si_la_tasa_cambia_solo_se_recalcula_la_parte_de_la_pasarela(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->acreditar($medico->reg_medico, 500);

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);
        $pago->update(['expira_el' => now()->subMinute()]);

        TasaDeCambio::fijar('40');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson(route('clinica.plan.refrescar', $pago), ['aplicar' => false])
            ->assertOk()
            ->assertJsonPath('estado', 'cambio')
            ->assertJsonPath('monto_texto', 'VES 600,00');

        $pago->refresh();
        $this->assertSame(54750, (int) $pago->monto_e2);
        $this->assertSame(500, (int) $pago->latidos_usados);
        $this->assertSame(500, (int) $pago->descuento_usd_e2);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson(route('clinica.plan.refrescar', $pago), ['aplicar' => true])
            ->assertOk()
            ->assertJsonPath('estado', 'actualizado')
            ->assertJsonPath('monto_texto', 'VES 600,00');

        $pago->refresh();
        $this->assertSame(60000, (int) $pago->monto_e2);
        $this->assertSame(500, (int) $pago->latidos_usados);
        $this->assertSame(500, (int) $pago->descuento_usd_e2);
        $this->assertSame(1500, (int) $pago->monto_pasarela_usd_e2);
        $this->assertSame('40.000000', (string) $pago->tasa);
    }

    public function test_el_comprobante_muestra_el_desglose_de_latidos(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->acreditar($medico->reg_medico, 2000);

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, null, $user->id);

        $html = (new \App\Mail\PagoDePlanAprobado($pago))->render();

        $this->assertStringContainsString('Descuento con Latidos', $html);
        $this->assertStringContainsString('2.000 Latidos', $html);
        $this->assertStringContainsString('Sin cobro', $html);
    }

    public function test_el_medico_ve_sus_latidos_y_sus_movimientos(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $this->acreditar($medico->reg_medico, 1200);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.latidos'))
            ->assertOk()
            ->assertSee('Mis Latidos')
            ->assertSee('1.200 Latidos')
            ->assertSee('Promoción');
    }

    public function test_sin_metodo_y_sin_latidos_suficientes_no_crea_el_pago(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.comprar'), [
                'plan_id' => $plan->id,
                'frecuencia' => Plan::MENSUAL,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Pago::where('reg_medico', $medico->reg_medico)->count());
    }
}
