<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `php artisan whatsapp:diagnostico`: reporta la configuración del webhook y, con `--firmar`, prueba el
 * camino completo contra el `APP_URL` con un POST firmado.
 *
 * Todo con `Http::fake()`: no sale a la red ni le manda nada a nadie.
 */
class DiagnosticoWhatsAppTest extends TestCase
{
    private function configurarCompleto(?string $secreto = 'secreto_de_prueba'): void
    {
        config([
            'services.whatsapp.app_secret' => $secreto,
            'services.whatsapp.verify_token' => 'verify_de_prueba',
            'services.whatsapp.token' => 'token_de_prueba',
            'services.whatsapp.phone_number_id' => '1362905180231894',
            'services.whatsapp.version' => 'v19.0',
            'app.url' => 'https://doctorisimo.test',
        ]);
    }

    public function test_sin_secreto_avisa_que_falta_y_que_el_webhook_responde_503(): void
    {
        $this->configurarCompleto(null);

        $codigo = Artisan::call('whatsapp:diagnostico');
        $salida = Artisan::output();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('WHATSAPP_APP_SECRET', $salida);
        $this->assertStringContainsString('503', $salida);
    }

    public function test_con_todo_configurado_termina_bien(): void
    {
        $this->configurarCompleto();

        $codigo = Artisan::call('whatsapp:diagnostico');
        $salida = Artisan::output();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('configuración básica está completa', $salida);
    }

    /** El POST de prueba lleva el cuerpo EXACTO que firma, y el evento es de status: no contesta a nadie. */
    public function test_firmar_manda_un_post_firmado_con_un_evento_de_status(): void
    {
        $this->configurarCompleto();
        Http::fake(['https://doctorisimo.test/api/whatsapp/webhook' => Http::response(['status' => 'EVENT_RECEIVED'], 200)]);

        $codigo = Artisan::call('whatsapp:diagnostico', ['--firmar' => true]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('HTTP 200', $salida);

        Http::assertSent(function ($request) {
            $firma = $request->header('X-Hub-Signature-256')[0] ?? '';
            $cuerpo = (string) $request->body();

            return $request->url() === 'https://doctorisimo.test/api/whatsapp/webhook'
                && $request->method() === 'POST'
                && $firma === 'sha256=' . hash_hmac('sha256', $cuerpo, 'secreto_de_prueba')
                && str_contains($cuerpo, '"statuses"')
                // Un payload de mensaje trae `from`; el de status, no. Así se comprueba que no contesta a nadie.
                && ! str_contains($cuerpo, '"from"');
        });
    }

    public function test_firmar_explica_el_403_como_secreto_equivocado(): void
    {
        $this->configurarCompleto();
        Http::fake(['*' => Http::response(['error' => 'Firma inválida'], 403)]);

        Artisan::call('whatsapp:diagnostico', ['--firmar' => true]);
        $salida = Artisan::output();

        $this->assertStringContainsString('403', $salida);
        $this->assertStringContainsString('app secret', $salida);
    }

    public function test_firmar_explica_el_503_como_secreto_que_el_webhook_no_ve(): void
    {
        $this->configurarCompleto();
        Http::fake(['*' => Http::response(['error' => 'Webhook no configurado'], 503)]);

        Artisan::call('whatsapp:diagnostico', ['--firmar' => true]);
        $salida = Artisan::output();

        $this->assertStringContainsString('503', $salida);
        $this->assertStringContainsString('config:clear', $salida);
    }

    public function test_sin_secreto_firmar_no_hace_ninguna_peticion(): void
    {
        $this->configurarCompleto(null);
        Http::fake();

        Artisan::call('whatsapp:diagnostico', ['--firmar' => true]);

        Http::assertNothingSent();
    }
}
