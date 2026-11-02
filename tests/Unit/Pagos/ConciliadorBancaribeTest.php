<?php

namespace Tests\Unit\Pagos;

use App\Models\CuentaBancaria;
use App\Pagos\Conciliacion\ConciliadorBancaribe;
use App\Pagos\Conciliacion\Conciliadores;
use App\Pagos\Conciliacion\ConsultaDePago;
use App\Pagos\Conciliacion\ResultadoDeConsulta;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * **La consulta de operaciones de Bancaribe**, con `Http::fake()`: qué se manda (token, RIF, referencia,
 * teléfono, fecha) y cómo se interpreta lo que vuelve.
 *
 * No hay tabla de `estadoEjecucion` en el documento del banco, así que se fija acá lo que sí se sabe: el
 * estado sale de `estadoTransaccion` (`EJ`) y de `descripcion`, y el monto se lee de `monto`.
 */
class ConciliadorBancaribeTest extends TestCase
{
    private const TOKEN_URL = 'https://banco.prueba/oauth2/token';

    private const CONSULTA_URL = 'https://banco.prueba/queryPaymentB2P';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pagos.pago_movil.bancaribe.token_url' => self::TOKEN_URL,
            'pagos.pago_movil.bancaribe.consulta_url' => self::CONSULTA_URL,
            'pagos.pago_movil.bancaribe.verificar_certificado' => false,
            'pagos.pago_movil.bancaribe.timeout' => 5,
        ]);

        Cache::flush();
    }

    private function cuenta(array $cambios = []): CuentaBancaria
    {
        $cuenta = new CuentaBancaria(array_merge([
            'propietario' => CuentaBancaria::MEDICO,
            'reg_medico' => 'gineco-00001',
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::PAGO_MOVIL,
            'banco_codigo' => '0114',
            'telefono' => '04141234567',
            'numero' => '01140000000000000000',
            'titular' => 'Consultorio de prueba',
            'documento_tipo' => 'J',
            'documento_numero' => '302597005',
            'conciliacion' => CuentaBancaria::CONCILIACION_AUTOMATICA,
            'credenciales' => ['consumer_key' => 'clave', 'consumer_secret' => 'secreto'],
            'activa' => true,
        ], $cambios));

        $cuenta->id = 7;

        return $cuenta;
    }

    private function consulta(array $cambios = []): ConsultaDePago
    {
        return new ConsultaDePago(
            tipo: $cambios['tipo'] ?? 'PM',
            referencia: $cambios['referencia'] ?? '000945672279',
            montoE2: $cambios['montoE2'] ?? 1000,
            documento: $cambios['documento'] ?? 'V12345678',
            telefono: $cambios['telefono'] ?? '04141234114',
        );
    }

    private function simular(array $operacion, int $estadoHttp = 200): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'token-de-prueba', 'expires_in' => 3600]),
            self::CONSULTA_URL => Http::response($operacion, $estadoHttp),
        ]);
    }

    public function test_interpreta_una_operacion_ejecutada_con_su_monto(): void
    {
        $this->simular([
            'estadoEjecucion' => 0,
            'descripcion' => 'EJECUTADO CORRECTAMENTE',
            'estadoTransaccion' => 'EJ',
            'monto' => '10.00',
            'codigoConfirmacion' => '000945672279',
            'cedulaCliente' => 'V12345678',
        ]);

        $resultado = (new ConciliadorBancaribe())->consultar($this->cuenta(), $this->consulta());

        $this->assertTrue($resultado->fueAprobado());
        $this->assertSame(1000, $resultado->montoE2);
        $this->assertSame('000945672279', $resultado->referencia);
    }

    public function test_interpreta_una_operacion_rechazada(): void
    {
        $this->simular([
            'descripcion' => 'OPERACION NEGADA POR EL BANCO',
            'estadoTransaccion' => 'RE',
        ]);

        $resultado = (new ConciliadorBancaribe())->consultar($this->cuenta(), $this->consulta());

        $this->assertSame(ResultadoDeConsulta::RECHAZADO, $resultado->estado);
        $this->assertStringContainsString('negada', mb_strtolower((string) $resultado->mensaje));
    }

    public function test_una_operacion_que_no_existe_no_se_confunde_con_un_rechazo(): void
    {
        $this->simular([
            'estadoEjecucion' => 141128,
            'descripcion' => 'No existe registro asociado a la transaccion',
        ]);

        $resultado = (new ConciliadorBancaribe())->consultar($this->cuenta(), $this->consulta());

        $this->assertSame(ResultadoDeConsulta::NO_ENCONTRADO, $resultado->estado);
    }

    public function test_manda_el_rif_del_comercio_y_los_datos_del_pagador(): void
    {
        $this->simular(['estadoTransaccion' => 'EJ', 'monto' => '10.00']);

        (new ConciliadorBancaribe())->consultar($this->cuenta(), $this->consulta());

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::CONSULTA_URL) {
                return false;
            }

            return $request['tipoTrx'] === 'PM'
                && $request['rif'] === 'J302597005'
                && $request['referencia'] === '000945672279'
                && $request['montoTransaccion'] === '10.00'
                && $request['identificadorPersona'] === 'V12345678'
                && $request['telefonoDebito'] === '04141234114'
                && $request->hasHeader('Authorization', 'Bearer token-de-prueba');
        });
    }

    public function test_una_transferencia_va_sin_telefono_y_con_tipo_trf(): void
    {
        $this->simular(['estadoTransaccion' => 'EJ', 'monto' => '10.00']);

        (new ConciliadorBancaribe())->consultar(
            $this->cuenta(),
            $this->consulta(['tipo' => 'TRF', 'telefono' => '']),
        );

        Http::assertSent(fn (Request $request) => $request->url() === self::CONSULTA_URL
            && $request['tipoTrx'] === 'TRF'
            && $request['telefonoDebito'] === '');
    }

    public function test_pide_el_token_una_vez_y_despues_usa_la_cache(): void
    {
        $this->simular(['estadoTransaccion' => 'EJ', 'monto' => '10.00']);

        $conciliador = new ConciliadorBancaribe();
        $conciliador->consultar($this->cuenta(), $this->consulta());
        $conciliador->consultar($this->cuenta(), $this->consulta());

        // Token + dos consultas: el segundo token no se pide.
        Http::assertSentCount(3);
    }

    public function test_el_token_va_con_basic_y_client_credentials(): void
    {
        $this->simular(['estadoTransaccion' => 'EJ', 'monto' => '10.00']);

        (new ConciliadorBancaribe())->consultar($this->cuenta(), $this->consulta());

        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_URL
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('clave:secreto'))
            && $request['grant_type'] === 'client_credentials');
    }

    public function test_una_respuesta_http_401_se_reporta_como_error(): void
    {
        $this->simular([
            'error' => 'invalid_client',
            'error_description' => 'Credenciales no válidas',
        ], 401);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credenciales no válidas');

        (new ConciliadorBancaribe())->consultar($this->cuenta(), $this->consulta());
    }

    public function test_probar_confirma_las_credenciales(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'token-de-prueba', 'expires_in' => 3600]),
        ]);

        $resultado = (new ConciliadorBancaribe())->probar($this->cuenta());

        $this->assertTrue($resultado['ok']);
    }

    public function test_probar_sin_credenciales_no_sale_a_la_red(): void
    {
        Http::fake();

        $resultado = (new ConciliadorBancaribe())->probar($this->cuenta(['credenciales' => null]));

        $this->assertFalse($resultado['ok']);
        Http::assertNothingSent();
    }

    public function test_una_cuenta_manual_no_tiene_conciliador(): void
    {
        $cuenta = $this->cuenta(['conciliacion' => CuentaBancaria::CONCILIACION_MANUAL]);

        $this->assertNull(Conciliadores::para($cuenta));
        $this->assertInstanceOf(ConciliadorBancaribe::class, Conciliadores::delBanco($cuenta));
    }

    public function test_un_banco_sin_api_no_tiene_conciliador(): void
    {
        $cuenta = $this->cuenta(['banco_codigo' => '0102']);

        $this->assertNull(Conciliadores::delBanco($cuenta));
        $this->assertNull(Conciliadores::para($cuenta));
    }

    public function test_una_cuenta_automatica_sin_credenciales_no_concilia(): void
    {
        $cuenta = $this->cuenta(['credenciales' => null]);

        $this->assertFalse($cuenta->conciliaSola());
        $this->assertNull(Conciliadores::para($cuenta));
    }
}
