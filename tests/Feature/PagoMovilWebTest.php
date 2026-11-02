<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\ConfiguracionPago;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoTransaccion;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Pagos\Cobros;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **El cobro por pago móvil y transferencia** en el link público (`/pagar/{token}`): el paciente paga desde
 * su banco, declara la referencia y el sistema la confirma —con la API de Bancaribe o a mano—.
 *
 * Lo que se fija acá:
 *  - el link muestra los datos de la cuenta (banco, teléfono, documento, titular) y no el formulario del OTP;
 *  - una cuenta con confirmación automática aprueba el pago cuando el banco lo confirma;
 *  - un monto distinto **no** se aprueba (todavía no hay abonos) y queda el motivo;
 *  - una cuenta manual deja el pago pendiente y avisa al consultorio;
 *  - la misma referencia no se acredita dos veces.
 *
 * `Http::fake` para no salir a Bancaribe y `DatabaseTransactions` como el resto de la suite.
 */
class PagoMovilWebTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN_URL = 'https://banco.prueba/oauth2/token';

    private const CONSULTA_URL = 'https://banco.prueba/queryPaymentB2P';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        ConfiguracionPago::actual()->update(['aplica_comision_metodo_en_citas_online' => false]);
        TasaDeCambio::fijar('36,5');

        config([
            'pagos.pago_movil.bancaribe.token_url' => self::TOKEN_URL,
            'pagos.pago_movil.bancaribe.consulta_url' => self::CONSULTA_URL,
            'pagos.pago_movil.bancaribe.verificar_certificado' => false,
        ]);

        Cache::flush();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoDePrueba(): Medico
    {
        $reg = 'pm-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dr. Pago Móvil',
            'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Dr. Pago Móvil',
            'lastname' => 'Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $reg,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return $medico;
    }

    private function cuentaDePagoMovil(string $conciliacion = CuentaBancaria::CONCILIACION_AUTOMATICA): CuentaBancaria
    {
        $credenciales = $conciliacion === CuentaBancaria::CONCILIACION_AUTOMATICA
            ? ['consumer_key' => 'clave', 'consumer_secret' => 'secreto']
            : null;

        return CuentaBancaria::create([
            'propietario' => CuentaBancaria::MEDICO,
            'reg_medico' => 'pm-' . substr(uniqid(), -6),
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::PAGO_MOVIL,
            'banco_codigo' => '0114',
            'numero' => '01140000000000000000',
            'telefono' => '04141234567',
            'titular' => 'Consultorio de prueba',
            'documento_tipo' => 'J',
            'documento_numero' => '302597005',
            'conciliacion' => $conciliacion,
            'credenciales' => $credenciales,
            'predeterminada' => true,
            'activa' => true,
        ]);
    }

    private function metodoDePagoMovil(CuentaBancaria $cuenta): MetodoPago
    {
        return MetodoPago::create([
            'propietario' => MetodoPago::PLATAFORMA,
            'reg_medico' => null,
            'pasarela' => 'pago_movil',
            'variante' => 'pago_movil',
            'nombre' => 'Pago móvil o transferencia',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::COBROS,
            'orden' => 1,
        ]);
    }

    private function citaDePrueba(Medico $medico): Cola
    {
        return Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha' => '2026-11-05',
            'hora_ini' => '08:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'monto' => null,
            'monto_pagado' => null,
        ]);
    }

    /** @return array<string,mixed> */
    private function declaracion(array $cambios = []): array
    {
        return array_merge([
            'tipo_pago' => 'PM',
            'documento_tipo' => 'V',
            'documento_numero' => '12345678',
            'nombre' => 'Paciente de prueba',
            'telefono' => '04141234114',
            'referencia' => '000945672279',
        ], $cambios);
    }

    private function falsificarBancaribe(array $operacion): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'token-de-prueba', 'expires_in' => 3600]),
            self::CONSULTA_URL => Http::response($operacion, 200),
        ]);
    }

    private function pagoDePrueba(Medico $medico, CuentaBancaria $cuenta): Pago
    {
        $cita = $this->citaDePrueba($medico);

        return app(Cobros::class)->crearParaCita($cita, $this->metodoDePagoMovil($cuenta), 5000);
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_el_link_muestra_los_datos_de_la_cuenta_y_no_el_formulario_del_otp(): void
    {
        Http::fake();

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil());

        $this->get(route('pagar.mostrar', $pago->token_publico))
            ->assertOk()
            ->assertSee('Paga desde tu banco')
            ->assertSee('Bancaribe')
            ->assertSee('04141234567')
            ->assertSee('J302597005')
            ->assertSee('Número de referencia')
            ->assertDontSee('Enviar código de confirmación');
    }

    public function test_una_cuenta_automatica_aprueba_el_pago_que_el_banco_confirma(): void
    {
        $this->falsificarBancaribe([
            'estadoTransaccion' => 'EJ',
            'descripcion' => 'EJECUTADO CORRECTAMENTE',
            'monto' => '1825.00',
            'codigoConfirmacion' => '000945672279',
        ]);

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil());

        $respuesta = $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion());

        $respuesta->assertRedirect(route('pagar.mostrar', $pago->token_publico));

        $pago->refresh();
        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertSame(182500, (int) $pago->monto_confirmado_e2);

        $transaccion = $pago->transacciones()->firstOrFail();
        $this->assertSame(PagoTransaccion::APROBADA, $transaccion->estado);

        $cita = $pago->cita();
        $this->assertNotNull($cita);
        $this->assertEqualsWithDelta(50.0, (float) $cita->monto_pagado, 0.01);
    }

    public function test_un_monto_distinto_no_aprueba_el_pago(): void
    {
        $this->falsificarBancaribe([
            'estadoTransaccion' => 'EJ',
            'descripcion' => 'EJECUTADO CORRECTAMENTE',
            'monto' => '1000.00',
            'codigoConfirmacion' => '000945672279',
        ]);

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil());

        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion())
            ->assertSessionHas('error');

        $pago->refresh();
        $this->assertSame(Pago::PENDIENTE, $pago->estado);

        $transaccion = $pago->transacciones()->firstOrFail();
        $this->assertSame(PagoTransaccion::RECHAZADA, $transaccion->estado);
        $this->assertNotEmpty($transaccion->mensaje);
    }

    public function test_una_cuenta_manual_deja_el_pago_pendiente_y_avisa_al_consultorio(): void
    {
        Http::fake();

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil(CuentaBancaria::CONCILIACION_MANUAL));

        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion())
            ->assertSessionHas('estado');

        $pago->refresh();
        $this->assertSame(Pago::PENDIENTE, $pago->estado);
        $this->assertSame(1, $pago->transacciones()->count());

        // No se consultó a ningún banco.
        Http::assertNothingSent();
    }

    public function test_el_monto_distinto_se_puede_reintentar_con_otra_referencia(): void
    {
        // El banco confirma 1.000 para la primera referencia y el monto correcto para la segunda.
        Http::fake(function ($request) {
            if ($request->url() === self::TOKEN_URL) {
                return Http::response(['access_token' => 'token-de-prueba', 'expires_in' => 3600]);
            }

            $monto = ($request['referencia'] ?? '') === '000945672280' ? '1825.00' : '1000.00';

            return Http::response([
                'estadoTransaccion' => 'EJ',
                'descripcion' => 'EJECUTADO CORRECTAMENTE',
                'monto' => $monto,
            ]);
        });

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil());

        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion());
        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado);

        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion(['referencia' => '000945672280']));

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(2, $pago->transacciones()->count());
    }

    public function test_una_transferencia_va_sin_telefono(): void
    {
        $this->falsificarBancaribe([
            'estadoTransaccion' => 'EJ',
            'descripcion' => 'EJECUTADO CORRECTAMENTE',
            'monto' => '1825.00',
        ]);

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil());

        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion([
            'tipo_pago' => 'TRF',
            'telefono' => '',
        ]))->assertSessionHas('estado');

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(PagoTransaccion::TIPO_TRANSFERENCIA, $pago->transacciones()->firstOrFail()->tipo);

        Http::assertSent(function ($request) {
            return $request->url() === self::CONSULTA_URL
                && $request['tipoTrx'] === 'TRF'
                && $request['telefonoDebito'] === '';
        });
    }

    public function test_el_pago_movil_exige_telefono_valido(): void
    {
        Http::fake();

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil());

        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion(['telefono' => '0414']))
            ->assertSessionHasErrors('telefono');

        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado);
    }

    public function test_la_misma_referencia_no_se_acredita_dos_veces(): void
    {
        $this->falsificarBancaribe([
            'estadoTransaccion' => 'EJ',
            'descripcion' => 'EJECUTADO CORRECTAMENTE',
            'monto' => '1825.00',
        ]);

        $medico = $this->medicoDePrueba();
        $pago = $this->pagoDePrueba($medico, $this->cuentaDePagoMovil());

        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion());
        $this->post(route('pagar.declarar', $pago->token_publico), $this->declaracion());

        $this->assertSame(1, $pago->transacciones()->count());
        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
    }
}
