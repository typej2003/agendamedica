<?php

namespace Tests\Feature;

use App\Mail\PagoDePlanAprobado;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\Specialty;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Pagos\Cobros;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Cobrar el plan del médico con SyPago** (MVP, 2026-10-21): la pantalla «Mi plan», el checkout con OTP,
 * el webhook, la conciliación y la renovación del servicio.
 *
 * SyPago se reemplaza con `Http::fake`: la suite no toca la red. Lo que se fija es la regla del dinero y la
 * idempotencia: un pago aprobado renueva el plan **una sola vez**, venga por donde venga (confirmación,
 * webhook o sondeo).
 */
class PlanPagoWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Un médico con su consultorio y su especialidad, como en el resto de las pruebas de la web. */
    private function medicoDePrueba(?string $sufijo = null): array
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $reg = 'pago-' . ($sufijo ?: substr(uniqid(), -6));

        $user = User::create([
            'name' => 'Dra. de prueba', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Pagos',
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

    private function planDePrueba(?string $codigo = null): Plan
    {
        $codigo = $codigo ?: 'plan-' . substr(uniqid(), -6);

        return Plan::create([
            'codigo' => $codigo,
            'nombre' => 'Plan de prueba',
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
            'reg_medico' => null,
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
            'reg_medico' => null,
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

    /** Los datos del pagador para el formulario. */
    private function pagador(): array
    {
        return [
            'documento_tipo' => 'V',
            'documento_numero' => '12345678',
            'nombre' => 'Dra. de prueba',
            'banco_codigo' => '0172',
            'cuenta' => '01340123456789012345',
        ];
    }

    /** Las respuestas de SyPago para el flujo de OTP y la consulta. */
    private function falsificarSyPago(string $transaccion = 'tx-1', string $estado = 'ACCP'): void
    {
        Http::fake(function (ClientRequest $request) use ($transaccion, $estado) {
            $url = $request->url();

            if (str_contains($url, 'request/otp')) {
                return Http::response(['transaction_id' => $transaccion], 200);
            }
            if (str_contains($url, 'transaction/otp')) {
                return Http::response(['status' => $estado, 'transaction_id' => $transaccion, 'ref_ibp' => '000123'], 200);
            }
            if (str_contains($url, 'transaction/' . $transaccion)) {
                return Http::response(['status' => $estado, 'transaction_id' => $transaccion, 'ref_ibp' => '000123'], 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_el_medico_ve_su_plan_y_los_precios_en_bolivares(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba('visible-' . substr(uniqid(), -5));
        $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.plan'))
            ->assertOk()
            ->assertSee('Mi plan')
            ->assertSee($plan->nombre)
            ->assertSee('USD 20,00')
            ->assertSee('VES 730,00')
            ->assertSee('1 USD = 36,5000 VES');
    }

    public function test_comprar_crea_un_pago_pendiente_con_la_tasa_congelada(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.comprar'), [
                'plan_id' => $plan->id,
                'metodo_pago_id' => $metodo->id,
            ])
            ->assertRedirect();

        $pago = Pago::latest('id')->firstOrFail();

        $this->assertSame($medico->reg_medico, $pago->reg_medico);
        $this->assertSame(Pago::PENDIENTE, $pago->estado);
        $this->assertSame(Pago::BENEFICIARIO_PLATAFORMA, $pago->beneficiario_tipo);
        $this->assertSame(2000, (int) $pago->monto_usd_e2);
        $this->assertSame(73000, (int) $pago->monto_e2); // 20 USD × 36,5 = 730,00 VES
        $this->assertSame('36.500000', (string) $pago->tasa);
        $this->assertNotNull($pago->expira_el);
    }

    public function test_iniciar_pide_el_otp_y_deja_el_pago_en_proceso(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago();

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.iniciar', $pago), $this->pagador())
            ->assertRedirect(route('clinica.plan.pagar', $pago));

        $pago->refresh();

        $this->assertSame(Pago::EN_PROCESO, $pago->estado);
        $this->assertNotNull($pago->otp_enviado_el);
        $this->assertSame('tx-1', $pago->referencia_externa);
        $this->assertSame('12345678', $pago->datos_pagador['documento_numero']);
        $this->assertArrayNotHasKey('otp', $pago->datos_pagador);
    }

    public function test_confirmar_aprueba_y_renueva_el_servicio(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago();

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.iniciar', $pago), $this->pagador());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.confirmar', $pago), ['otp' => '123456'])
            ->assertRedirect(route('clinica.plan.pagar', $pago));

        $pago->refresh();
        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertNotNull($pago->pagado_el);

        $servicio = RegMedicoServicio::where('reg_medico', $medico->reg_medico)
            ->where('origen', RegMedicoServicio::ORIGEN_COMPRA)
            ->firstOrFail();
        $this->assertSame(RegMedicoServicio::ORIGEN_COMPRA, $servicio->origen);
        $this->assertSame('20.00', (string) $servicio->monto_usd);
        $this->assertSame($plan->id, $servicio->plan_id);

        Mail::assertSent(PagoDePlanAprobado::class, fn (PagoDePlanAprobado $mail) => $mail->hasTo($user->email));
    }

    public function test_el_webhook_aprueba_y_renueva_una_sola_vez(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago('tx-webhook');

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);
        $pago->update([
            'estado' => Pago::EN_PROCESO,
            'referencia_externa' => 'tx-webhook',
            'otp_enviado_el' => now(),
        ]);

        $aviso = [
            'internal_id' => $pago->referencia,
            'status' => 'ACCP',
            'transaction_id' => 'tx-webhook',
            'amount' => ['amt' => '730.00', 'currency' => 'VES'],
        ];

        $this->postJson(route('pagos.webhook.sypago', ['token' => $metodo->webhook_token]), $aviso)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(1, $this->comprasDelMedico($medico));

        // El mismo aviso otra vez: no renueva de nuevo.
        $this->postJson(route('pagos.webhook.sypago', ['token' => $metodo->webhook_token]), $aviso)
            ->assertOk();

        $this->assertSame(1, $this->comprasDelMedico($medico));
    }

    /** Cuántos servicios con origen `compra` tiene el médico (el de prueba del registro no cuenta). */
    private function comprasDelMedico(Medico $medico): int
    {
        return RegMedicoServicio::where('reg_medico', $medico->reg_medico)
            ->where('origen', RegMedicoServicio::ORIGEN_COMPRA)
            ->count();
    }

    public function test_el_webhook_con_token_invalido_da_404_y_con_monto_distinto_no_aprueba(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago('tx-monto');

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);
        $pago->update(['estado' => Pago::EN_PROCESO, 'referencia_externa' => 'tx-monto', 'otp_enviado_el' => now()]);

        $this->postJson('/api/pagos/sypago/token-que-no-existe', [
            'internal_id' => $pago->referencia,
            'status' => 'ACCP',
        ])->assertNotFound();

        $this->postJson(route('pagos.webhook.sypago', ['token' => $metodo->webhook_token]), [
            'internal_id' => $pago->referencia,
            'status' => 'ACCP',
            'transaction_id' => 'tx-monto',
            'amount' => ['amt' => '1.00', 'currency' => 'VES'],
        ])->assertStatus(422);

        $this->assertSame(Pago::EN_PROCESO, $pago->fresh()->estado);
    }

    public function test_el_sondeo_aprueba_un_pago_que_quedo_en_proceso(): void
    {
        [$user, $medico] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago('tx-sondeo');

        $pago = app(Cobros::class)->crearParaPlan($medico->reg_medico, $plan, $metodo, $user->id);
        // `updated_at` no es fillable: se mueve con el query builder para que el sondeo lo tome.
        Pago::whereKey($pago->id)->update([
            'estado' => Pago::EN_PROCESO,
            'referencia_externa' => 'tx-sondeo',
            'otp_enviado_el' => now(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->artisan('pagos:conciliar')->assertExitCode(0);

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(1, $this->comprasDelMedico($medico));
    }

    public function test_sin_tasa_no_se_puede_comprar_un_plan(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.plan.comprar'), [
                'plan_id' => $plan->id,
                'metodo_pago_id' => $metodo->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Pago::count());
    }

    public function test_un_medico_no_puede_ver_el_pago_de_otro(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        [$otroUser, $otroMedico] = $this->medicoDePrueba('otro-' . substr(uniqid(), -5));

        $plan = $this->planDePrueba();
        $metodo = $this->metodoDePrueba();
        TasaDeCambio::fijar('36,5', TasaDeCambio::MANUAL, null, now()->subMinute());

        $pagoAjeno = app(Cobros::class)->crearParaPlan($otroMedico->reg_medico, $plan, $metodo, $otroUser->id);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.plan.pagar', $pagoAjeno))
            ->assertNotFound();
    }
}
