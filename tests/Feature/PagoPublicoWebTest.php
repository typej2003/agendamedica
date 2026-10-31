<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\ConfiguracionPago;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Paciente;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Pagos\Cobros;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **El link público de pago de una cita** (`/pagar/{token}`, punto 2 del cobro): la ruta pública y su
 * pantalla, sin el botón que crea el link desde la agenda.
 *
 * Lo que se fija acá:
 *  - el token inexistente es un 404 seco y el link vigente muestra médico, fecha, hora, concepto y monto
 *    (en VES y en USD) **sin** datos del paciente;
 *  - el flujo completo pagador → OTP → aprobado deja la cita pagada con el mismo motor (`Cobros`);
 *  - un pago aprobado no se vuelve a pagar;
 *  - un link vencido no deja iniciar el cobro;
 *  - una cita ya pagada por otro pago aprobado no se cobra dos veces.
 *
 * `DatabaseTransactions` por la razón de siempre (ver MedicAPI/AGENTS.md) y `Http::fake` para no salir a
 * SyPago. El `throttle` se apaga en las pruebas: no es lo que se está midiendo y el contador por IP
 * sobreviviría entre casos del mismo proceso.
 */
class PagoPublicoWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        ConfiguracionPago::actual()->update(['aplica_comision_metodo_en_citas_online' => false]);
        TasaDeCambio::fijar('36,5');
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoDePrueba(): Medico
    {
        $reg = 'pago-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dra. de prueba',
            'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Dra. de prueba',
            'lastname' => 'Cobros',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $reg,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return $medico;
    }

    private function cuentaDeLaPlataforma(): CuentaBancaria
    {
        return CuentaBancaria::create([
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
    }

    private function metodoDeLaPlataforma(): MetodoPago
    {
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
            'cuenta_bancaria_id' => $this->cuentaDeLaPlataforma()->id,
            'disponible_para' => MetodoPago::AMBOS,
            'orden' => 1,
        ]);
    }

    private function citaDePrueba(Medico $medico, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'fecha' => '2026-10-05',
            'hora_ini' => '08:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'monto' => null,
            'monto_pagado' => null,
        ], $extra));
    }

    /** @return array<string,string> */
    private function pagador(): array
    {
        return [
            'documento_tipo' => 'V',
            'documento_numero' => '12345678',
            'nombre' => 'Paciente de prueba',
            'banco_codigo' => '0134',
            'cuenta' => '01340123456789012345',
        ];
    }

    private function falsificarSyPago(string $transaccion = 'tx-cita'): void
    {
        Http::fake(function (ClientRequest $request) use ($transaccion) {
            $url = $request->url();

            if (str_contains($url, 'request/otp')) {
                return Http::response(['transaction_id' => $transaccion], 200);
            }
            if (str_contains($url, 'transaction/otp')) {
                return Http::response([
                    'status' => 'ACCP',
                    'transaction_id' => $transaccion,
                    'ref_ibp' => '000123',
                ], 200);
            }
            if (str_contains($url, 'transaction/' . $transaccion)) {
                return Http::response([
                    'status' => 'ACCP',
                    'transaction_id' => $transaccion,
                    'ref_ibp' => '000123',
                ], 200);
            }

            return Http::response([], 404);
        });
    }

    /** Crea el pago del link y devuelve su token. */
    private function pagoDePrueba(Medico $medico, Cola $cita): Pago
    {
        return app(Cobros::class)->crearParaCita($cita, $this->metodoDeLaPlataforma(), 5000);
    }

    private function aprobar(Pago $pago): void
    {
        $this->assertTrue(app(Cobros::class)->iniciar($pago, $this->pagador())->ok);
        $this->assertTrue(app(Cobros::class)->confirmar($pago->fresh(), '123456')->ok);
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_un_token_inexistente_da_404(): void
    {
        $this->get('/pagar/token-que-no-existe')->assertNotFound();
    }

    public function test_el_link_vigente_muestra_el_resumen_y_no_filtra_datos_del_paciente(): void
    {
        // La pantalla pide el catálogo de bancos al abrir el formulario: que no salga a la red.
        Http::fake();

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico, ['numhistoria' => 77889911]);

        // Una ficha real enlazada a la cita, para que la prueba de privacidad no sea sobre la nada.
        $paciente = Paciente::create([
            'cedula' => 'PRIV123456',
            'nombres' => 'María Privada',
            'apellidos' => 'Delpaciente',
            'telefono' => 'PRIV-0414-5556677',
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'numhistoria' => '77889911',
            'reg_medico' => $medico->reg_medico,
        ]);

        $pago = $this->pagoDePrueba($medico, $cita);
        $url = route('pagar.mostrar', $pago->token_publico);

        $respuesta = $this->get($url);

        $respuesta->assertOk()
            ->assertSee('Paga tu cita')
            ->assertSee($medico->nombre_mostrar)
            ->assertSee('05/10/2026')
            ->assertSee('8:00 AM')
            ->assertSee('Cita del 05/10/2026')
            ->assertSee('VES 1.825,00')
            ->assertSee('USD 50,00')
            ->assertSee('Enviar código de confirmación');

        // Lo único que viaja en la página es lo que se puede mostrar en público.
        $respuesta->assertDontSee('María Privada')
            ->assertDontSee('Delpaciente')
            ->assertDontSee('PRIV123456')
            ->assertDontSee('PRIV-0414-5556677')
            ->assertDontSee('77889911');

        // El link no se indexa, no filtra el token por `Referer` y no queda en caché.
        $respuesta->assertHeader('Referrer-Policy', 'no-referrer');
        $respuesta->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }

    public function test_el_formulario_ofrece_los_bancos_activos_de_sypago(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), 'banks')) {
                return Http::response(['data' => [
                    ['Code' => '0172', 'Name' => 'Bancamiga', 'Active' => true, 'IsDebitOTP' => true],
                    ['Code' => '0102', 'Name' => 'Banco de Venezuela', 'Active' => true, 'IsDebitOTP' => true],
                    ['Code' => '0999', 'Name' => 'Banco inactivo', 'Active' => false, 'IsDebitOTP' => true],
                ]], 200);
            }

            return Http::response([], 404);
        });

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico);
        $pago = $this->pagoDePrueba($medico, $cita);

        $this->get(route('pagar.mostrar', $pago->token_publico))
            ->assertOk()
            ->assertSee('Bancamiga')
            ->assertSee('Banco de Venezuela')
            ->assertDontSee('Banco inactivo');
    }

    public function test_el_flujo_pagador_otp_aprobado_deja_la_cita_pagada(): void
    {
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico);
        $pago = $this->pagoDePrueba($medico, $cita);
        $url = route('pagar.mostrar', $pago->token_publico);

        $this->get($url)->assertOk()->assertSee('Enviar código de confirmación');

        $inicio = $this->post(route('pagar.iniciar', $pago->token_publico), $this->pagador());

        $inicio->assertRedirect($url);
        $inicio->assertSessionHas('estado');

        $pago->refresh();
        $this->assertSame(Pago::EN_PROCESO, $pago->estado);
        $this->assertSame('0134', $pago->datos_pagador['banco_codigo']);
        $this->assertSame('12345678', $pago->datos_pagador['documento_numero']);

        $this->get($url)
            ->assertOk()
            ->assertSee('Confirma con el código')
            ->assertDontSee('Enviar código de confirmación');

        $confirmacion = $this->post(route('pagar.confirmar', $pago->token_publico), ['otp' => '123456']);

        $confirmacion->assertRedirect($url);
        $confirmacion->assertSessionHas('estado');

        $pago->refresh();
        $this->assertSame(Pago::APROBADO, $pago->estado);

        $cita->refresh();
        $this->assertSame(50.0, (float) $cita->monto);
        $this->assertSame(50.0, (float) $cita->monto_pagado);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->estadoPago());

        $this->get($url)
            ->assertOk()
            ->assertSee('Pago aprobado')
            ->assertSee('tx-cita')
            ->assertSee('USD 50,00')
            ->assertDontSee('Enviar código de confirmación');
    }

    public function test_consultar_reconcilia_el_pago_con_sypago(): void
    {
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico);
        $pago = $this->pagoDePrueba($medico, $cita);
        $url = route('pagar.mostrar', $pago->token_publico);

        $this->post(route('pagar.iniciar', $pago->token_publico), $this->pagador())->assertRedirect($url);

        // En vez de escribir el código, el paciente pide consultar el estado: SyPago ya lo tiene aprobado.
        $this->post(route('pagar.consultar', $pago->token_publico))
            ->assertRedirect($url)
            ->assertSessionHas('estado');

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
    }

    public function test_un_pago_aprobado_no_deja_volver_a_pagar(): void
    {
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico);
        $pago = $this->pagoDePrueba($medico, $cita);
        $this->aprobar($pago);

        $url = route('pagar.mostrar', $pago->token_publico);

        $this->get($url)
            ->assertOk()
            ->assertSee('Pago aprobado')
            ->assertDontSee('Enviar código de confirmación');

        $this->post(route('pagar.iniciar', $pago->token_publico), $this->pagador())
            ->assertRedirect($url)
            ->assertSessionHas('error');

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
    }

    public function test_un_link_vencido_no_deja_pagar(): void
    {
        Http::fake();

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico);
        $pago = $this->pagoDePrueba($medico, $cita);
        $pago->update(['expira_el' => now()->subMinute()]);

        $url = route('pagar.mostrar', $pago->token_publico);

        $this->get($url)
            ->assertOk()
            ->assertSee('Este link venció')
            ->assertDontSee('Enviar código de confirmación');

        $this->post(route('pagar.iniciar', $pago->token_publico), $this->pagador())
            ->assertRedirect($url)
            ->assertSessionHas('error');

        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_una_cita_ya_pagada_por_otro_pago_no_se_cobra_dos_veces(): void
    {
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico);

        // El primer link se paga: la cita queda pagada.
        $primero = $this->pagoDePrueba($medico, $cita);
        $this->aprobar($primero);

        // Llega un segundo link de la misma cita.
        $segundo = $this->pagoDePrueba($medico, $cita);
        $url = route('pagar.mostrar', $segundo->token_publico);

        $this->get($url)
            ->assertOk()
            ->assertSee('Esta cita ya está pagada')
            ->assertDontSee('Enviar código de confirmación');

        $this->post(route('pagar.iniciar', $segundo->token_publico), $this->pagador())
            ->assertRedirect($url)
            ->assertSessionHas('error');

        $this->assertSame(Pago::PENDIENTE, $segundo->fresh()->estado);
        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
    }
}
