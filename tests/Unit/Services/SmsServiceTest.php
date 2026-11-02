<?php

namespace Tests\Unit\Services;

use App\Services\SmsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **El proveedor de SMS del servidor** (O-55): el que se elige con `SMS_PROVEEDOR` detrás de
 * `SmsService`, sin que los que lo llaman se enteren.
 *
 * Lo que se fija acá es el contrato —`configurado()` y `enviar()` → `['ok' => bool, 'respuesta' => array]`—
 * en sus tres formas: el apagado, el de desarrollo y el de verdad.
 */
class SmsServiceTest extends TestCase
{
    private function proveedor(string $cual): SmsService
    {
        config(['services.sms.proveedor' => $cual]);

        return app(SmsService::class);
    }

    /** Sin la variable, el servidor sigue hablando con Twilio: lo que ya funcionaba no cambia. */
    public function test_sin_la_variable_el_proveedor_es_twilio(): void
    {
        config(['services.sms' => []]);

        $this->assertSame(SmsService::TWILIO, app(SmsService::class)->proveedor());
    }

    /** Un valor que no existe cae en el de siempre y no rompe: la configuración no puede apagar los SMS sin querer. */
    public function test_un_proveedor_desconocido_cae_en_twilio(): void
    {
        $this->assertSame(SmsService::TWILIO, $this->proveedor('telegram')->proveedor());
    }

    public function test_ninguno_no_esta_configurado_y_no_manda(): void
    {
        $sms = $this->proveedor(SmsService::NINGUNO);

        $this->assertFalse($sms->configurado());

        $envio = $sms->enviar('584141234567', 'Hola');

        $this->assertFalse($envio['ok']);
        $this->assertArrayHasKey('error', $envio['respuesta']);
        Http::assertNothingSent();
    }

    public function test_log_esta_configurado_y_devuelve_ok_sin_tocar_la_red(): void
    {
        $sms = $this->proveedor(SmsService::LOG);

        $this->assertTrue($sms->configurado());

        $envio = $sms->enviar('584141234567', 'Feliz cumpleaños');

        $this->assertTrue($envio['ok']);
        $this->assertTrue($envio['respuesta']['simulado']);
        Http::assertNothingSent();
    }

    /** Twilio sin las tres variables no está configurado: el envío falla con un motivo, no con una excepción. */
    public function test_twilio_sin_credenciales_no_esta_configurado(): void
    {
        config(['services.twilio' => ['sid' => '', 'token' => '', 'from' => '']]);

        $sms = $this->proveedor(SmsService::TWILIO);

        $this->assertFalse($sms->configurado());
        $this->assertFalse($sms->enviar('584141234567', 'Hola')['ok']);
    }

    /** Y con credenciales manda por HTTP, que es el camino de siempre. */
    public function test_twilio_con_credenciales_manda_y_devuelve_la_respuesta(): void
    {
        config(['services.twilio' => ['sid' => 'AC123', 'token' => 'secreto', 'from' => '+15550001111']]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);

        $sms = $this->proveedor(SmsService::TWILIO);
        $envio = $sms->enviar('584141234567', 'Hola');

        $this->assertTrue($sms->configurado());
        $this->assertTrue($envio['ok']);
        $this->assertSame('SM1', $envio['respuesta']['sid']);
        Http::assertSent(fn ($peticion) => $peticion['To'] === '+584141234567'
            && $peticion['From'] === '+15550001111'
            && $peticion['Body'] === 'Hola');
    }
}
