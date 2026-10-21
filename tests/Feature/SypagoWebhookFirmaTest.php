<?php

namespace Tests\Feature;

use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Plan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **La firma del webhook de SyPago** (2026-10-21): `X-Signature` (ECDSA + SHA-256) sobre
 * `"{payload crudo}.{X-Signature-Nonce}.{operation_secret}"`, verificada con la clave pública de
 * `GET user/key`.
 *
 * Lo que se fija: una notificación firmada se acepta; una con la firma alterada se rechaza con 403 y no
 * aprueba nada (aunque el monto y el `internal_id` sean correctos).
 */
class SypagoWebhookFirmaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Un par EC P-256 fijo, sólo para la prueba: no es una credencial real. Se usa un par embebido porque
     * `openssl_pkey_new()` en este Windows no encuentra `openssl.cnf`; firmar y verificar con un PEM sí funciona.
     */
    private const CLAVE_PRIVADA = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIN/90mfGlj27RrOJbeOypyvTZ+sLjsRhg8XMTSTrdCVUoAoGCCqGSM49
AwEHoUQDQgAEITddicbG/Ze3NL9DMlgaUc60XbnV3jPyjz1O2GamBmpADsf8gMRe
Gxo2y4XQPe2LBMoSpQe5aT8WPkAbbBHrZQ==
-----END EC PRIVATE KEY-----
PEM;

    private const CLAVE_PUBLICA = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEITddicbG/Ze3NL9DMlgaUc60XbnV
3jPyjz1O2GamBmpADsf8gMReGxo2y4XQPe2LBMoSpQe5aT8WPkAbbBHrZQ==
-----END PUBLIC KEY-----
PEM;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @return array{0:MetodoPago,1:Pago,2:string,3:string,4:string} */
    private function escenario(string $firmaValida = 'si'): array
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

        $metodo = MetodoPago::create([
            'propietario' => MetodoPago::PLATAFORMA,
            'reg_medico' => null,
            'pasarela' => 'sypago',
            'variante' => 'debito_inmediato',
            'nombre' => 'SyPago · Débito inmediato',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'credenciales' => [
                'tipo' => 'jwt',
                'access_token' => 'jwt-firma',
                'host' => 'https://api.sypago.test/api/v1/',
            ],
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::AMBOS,
        ]);

        $plan = Plan::create([
            'codigo' => 'firma-' . substr(uniqid(), -6),
            'nombre' => 'Plan de firma',
            'frecuencia' => Plan::MENSUAL,
            'precio_usd' => 20,
            'visible' => true,
            'activo' => true,
            'orden' => 20,
        ]);

        $pago = Pago::create([
            'referencia' => (string) Str::uuid(),
            'reg_medico' => 'firma-' . substr(uniqid(), -6),
            'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
            'canal' => Pago::CANAL_PLAN,
            'pagable_tipo' => Pago::PAGABLE_PLAN,
            'pagable_id' => $plan->id,
            'concepto' => 'Plan de firma',
            'metodo_pago_id' => $metodo->id,
            'moneda' => 'VES',
            'monto_e2' => 73000,
            'monto_usd_e2' => 2000,
            'tasa' => '36.500000',
            'neto_usd_e2' => 2000,
            'neto_ves_e2' => 73000,
            'estado' => Pago::EN_PROCESO,
            'referencia_externa' => 'tx-firma',
            'operation_secret' => 'secreto-de-operacion',
            'otp_enviado_el' => now(),
            'estado_liquidacion' => Pago::LIQUIDACION_NO_APLICA,
        ]);

        $clave = self::CLAVE_PRIVADA;
        $publica = self::CLAVE_PUBLICA;

        $nonce = '1700000000000';
        $payload = json_encode([
            'internal_id' => $pago->referencia,
            'status' => 'ACCP',
            'transaction_id' => 'tx-firma',
            'amount' => ['amt' => '730.00', 'currency' => 'VES'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stringToSign = $payload . '.' . $nonce . '.' . $pago->operation_secret;
        openssl_sign($stringToSign, $firma, $clave, OPENSSL_ALGO_SHA256);

        Http::fake(function (ClientRequest $request) use ($publica) {
            if (str_contains($request->url(), 'user/key')) {
                return Http::response(['public_key' => $publica], 200);
            }

            return Http::response(['status' => 'ACCP', 'transaction_id' => 'tx-firma'], 200);
        });

        // La firma "inválida" se altera después de calcularla.
        $firmaB64 = base64_encode($firma);
        if ($firmaValida !== 'si') {
            $firmaB64 = base64_encode(str_repeat('x', 64));
        }

        return [$metodo, $pago, $payload, $firmaB64, $nonce];
    }

    private function enviar(array $escenario)
    {
        [$metodo, $pago, $payload, $firma, $nonce] = $escenario;

        return $this->call(
            'POST',
            route('pagos.webhook.sypago', ['token' => $metodo->webhook_token]),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => $firma,
                'HTTP_X_SIGNATURE_NONCE' => $nonce,
            ],
            $payload
        );
    }

    public function test_una_firma_valida_aprueba_el_pago(): void
    {
        $escenario = $this->escenario('si');
        [$metodo, $pago] = $escenario;

        $this->enviar($escenario)->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
    }

    public function test_una_firma_invalida_da_403_y_no_aprueba(): void
    {
        $escenario = $this->escenario('no');
        [$metodo, $pago] = $escenario;

        $this->enviar($escenario)->assertStatus(403);

        $this->assertSame(Pago::EN_PROCESO, $pago->fresh()->estado);
    }
}
