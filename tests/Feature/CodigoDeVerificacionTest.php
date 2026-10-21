<?php

namespace Tests\Feature;

use App\Reservas\CodigoDeVerificacion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **El código de 6 dígitos y sus canales** (2026-10-19; Twilio confirmado en QA/producción el 2026-10-21).
 *
 * No manda nada de verdad: `Http::fake()` intercepta Twilio y WhatsApp. Lo que fija:
 *
 *  - con Twilio configurado, el SMS sale con el `To` en E.164, el `From` del `.env` y el código en el texto;
 *  - si hay plantilla de WhatsApp, se usa primero y **no** se manda SMS;
 *  - si WhatsApp falla, cae a Twilio (el respaldo que hace que el portal siga funcionando);
 *  - sin ningún canal, en testing se simula y no se hace ninguna petición.
 */
class CodigoDeVerificacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', '');
        config()->set('services.whatsapp.phone_number_id', '');
        config()->set('services.whatsapp.template_codigo', '');
        config()->set('services.twilio.sid', '');
        config()->set('services.twilio.token', '');
        config()->set('services.twilio.from', '');
    }

    public function test_envia_el_codigo_por_twilio_cuando_esta_configurado(): void
    {
        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token-de-prueba');
        config()->set('services.twilio.from', '+15551234567');

        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201),
        ]);

        $resultado = app(CodigoDeVerificacion::class)->enviar('584145556677', '123456');

        $this->assertTrue($resultado['ok']);
        $this->assertSame(CodigoDeVerificacion::CANAL_SMS, $resultado['canal']);

        Http::assertSent(function (Request $peticion) {
            return $peticion->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
                && $peticion['To'] === '+584145556677'
                && $peticion['From'] === '+15551234567'
                && str_contains((string) $peticion['Body'], '123456')
                && str_contains((string) $peticion['Body'], 'verificación de Doctorísimo');
        });
    }

    public function test_usa_whatsapp_primero_si_hay_plantilla_y_no_manda_sms(): void
    {
        config()->set('services.whatsapp.token', 'token-meta');
        config()->set('services.whatsapp.phone_number_id', '123456789');
        config()->set('services.whatsapp.template_codigo', 'codigo_cita');
        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token-de-prueba');
        config()->set('services.twilio.from', '+15551234567');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
        ]);

        $resultado = app(CodigoDeVerificacion::class)->enviar('584145556677', '123456');

        $this->assertTrue($resultado['ok']);
        $this->assertSame(CodigoDeVerificacion::CANAL_WHATSAPP, $resultado['canal']);

        Http::assertSent(function (Request $peticion) {
            return str_contains($peticion->url(), 'graph.facebook.com')
                && $peticion['template']['name'] === 'codigo_cita'
                && $peticion['template']['components'][0]['parameters'][0]['text'] === '123456';
        });

        Http::assertNotSent(fn (Request $peticion) => str_contains($peticion->url(), 'api.twilio.com'));
    }

    public function test_si_whatsapp_falla_cae_a_twilio(): void
    {
        config()->set('services.whatsapp.token', 'token-meta');
        config()->set('services.whatsapp.phone_number_id', '123456789');
        config()->set('services.whatsapp.template_codigo', 'codigo_cita');
        config()->set('services.twilio.sid', 'AC123');
        config()->set('services.twilio.token', 'token-de-prueba');
        config()->set('services.twilio.from', '+15551234567');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'rechazada']], 500),
            'api.twilio.com/*' => Http::response(['sid' => 'SM456', 'status' => 'queued'], 201),
        ]);

        $resultado = app(CodigoDeVerificacion::class)->enviar('584145556677', '654321');

        $this->assertTrue($resultado['ok']);
        $this->assertSame(CodigoDeVerificacion::CANAL_SMS, $resultado['canal']);

        Http::assertSent(fn (Request $peticion) => str_contains($peticion->url(), 'api.twilio.com')
            && str_contains((string) $peticion['Body'], '654321'));
    }

    public function test_sin_canales_en_testing_simula_y_no_hace_peticiones(): void
    {
        Http::fake();

        $resultado = app(CodigoDeVerificacion::class)->enviar('584145556677', '123456');

        $this->assertTrue($resultado['ok']);
        $this->assertSame(CodigoDeVerificacion::CANAL_SIMULADO, $resultado['canal']);
        Http::assertNothingSent();
    }
}
