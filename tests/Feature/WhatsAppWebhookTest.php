<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El transporte del chatbot de WhatsApp y el **mínimo antes de producción** de la auditoría del
 * 2026-10-17 (§6): firma obligatoria, deduplicación por `messageId`, errores que piden el reintento de
 * Meta y verificación de TLS activa.
 */
class WhatsAppWebhookTest extends TestCase
{
    private const SECRETO = 'secreto_de_prueba_123';

    private const VERIFY_TOKEN = 'token_de_prueba_123';

    // ------------------------------------------------------------------ Verificación del número (GET)

    public function test_verify_webhook_success()
    {
        config(['services.whatsapp.verify_token' => self::VERIFY_TOKEN]);

        $response = $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=' . self::VERIFY_TOKEN . '&hub_challenge=desafio_meta');

        $response->assertStatus(200);
        $this->assertEquals('desafio_meta', $response->getContent());
    }

    public function test_verify_webhook_fails_with_invalid_token()
    {
        config(['services.whatsapp.verify_token' => self::VERIFY_TOKEN]);

        $response = $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=token_equivocado&hub_challenge=desafio_meta');

        $response->assertStatus(403);
    }

    /**
     * Sin `WHATSAPP_VERIFY_TOKEN` la comparación de antes (`null === null`) daba por buena una petición sin
     * token: cualquiera podía "verificar" el número. Ahora no se verifica nada.
     */
    public function test_verify_webhook_sin_token_configurado_no_acepta_la_verificacion()
    {
        config(['services.whatsapp.verify_token' => '']);

        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=&hub_challenge=desafio_meta')
            ->assertStatus(503);

        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_challenge=desafio_meta')
            ->assertStatus(503);
    }

    // ------------------------------------------------------------------------------- Firma (POST)

    public function test_un_post_sin_firma_se_rechaza_y_no_procesa_el_mensaje()
    {
        config([
            'services.whatsapp.app_secret' => self::SECRETO,
            'services.whatsapp.token' => 'test_token',
            'services.whatsapp.phone_number_id' => 'id_por_defecto_111',
        ]);

        $this->mock(GeminiService::class)->shouldNotReceive('generarRespuesta');
        $this->mock(WhatsAppService::class)->shouldNotReceive('sendMessage');

        $this->postJson('/api/whatsapp/webhook', $this->payloadDeTexto())
            ->assertStatus(403);
    }

    public function test_un_post_con_firma_invalida_se_rechaza()
    {
        config(['services.whatsapp.app_secret' => self::SECRETO]);

        $this->mock(GeminiService::class)->shouldNotReceive('generarRespuesta');

        $payload = $this->payloadDeTexto();
        $headers = ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'otro_secreto')];

        $this->postJson('/api/whatsapp/webhook', $payload, $headers)->assertStatus(403);
    }

    /** Sin el secreto configurado no se acepta **nada**: es el agujero que cerraba `null === null`. */
    public function test_sin_secreto_configurado_el_webhook_no_acepta_posts()
    {
        config(['services.whatsapp.app_secret' => '']);

        $this->mock(GeminiService::class)->shouldNotReceive('generarRespuesta');

        $response = $this->postJson('/api/whatsapp/webhook', $this->payloadDeTexto(), [
            'X-Hub-Signature-256' => 'sha256=cualquier_cosa',
        ]);

        $response->assertStatus(503);
    }

    // ------------------------------------------------------------------- Procesamiento de un mensaje

    public function test_un_mensaje_firmado_se_procesa_y_se_contesta_con_el_phone_number_id_del_webhook()
    {
        $this->credenciales();

        $mockGemini = $this->mock(GeminiService::class);
        $mockGemini->shouldReceive('generarRespuesta')
            ->once()
            ->with('Hola', '584165800403')
            ->andReturn('¡Hola! Bienvenido.');

        $mockWhatsApp = $this->mock(WhatsAppService::class);
        $mockWhatsApp->shouldReceive('sendMessage')
            ->once()
            ->with('584165800403', '¡Hola! Bienvenido.', '1362905180231894')
            ->andReturn(true);

        $response = $this->postFirmado($this->payloadDeTexto());

        $response->assertStatus(200);
        $response->assertJson(['status' => 'EVENT_RECEIVED']);
    }

    /**
     * Meta reintenta el mismo `messageId` cuando el webhook no contesta 200. Sin la deduplicación, cada
     * reintento volvía a responderle a la paciente y a crear otra cita (auditoría, riesgo 2).
     */
    public function test_un_reintento_del_mismo_message_id_no_se_procesa_dos_veces()
    {
        $this->credenciales();

        $mockGemini = $this->mock(GeminiService::class);
        $mockGemini->shouldReceive('generarRespuesta')->once()->andReturn('¡Hola! Bienvenido.');

        $mockWhatsApp = $this->mock(WhatsAppService::class);
        $mockWhatsApp->shouldReceive('sendMessage')->once()->andReturn(true);

        $payload = $this->payloadDeTexto();

        $this->postFirmado($payload)->assertStatus(200);
        $this->postFirmado($payload)->assertStatus(200);
    }

    /**
     * Un envío fallido ya no queda en un 200 silencioso: se responde 500 para que Meta reintente, y la
     * marca de "ya procesado" se suelta para que el reintento sí conteste.
     */
    public function test_un_fallo_al_enviar_responde_500_y_el_reintento_vuelve_a_procesar()
    {
        $this->credenciales();

        $mockGemini = $this->mock(GeminiService::class);
        $mockGemini->shouldReceive('generarRespuesta')->twice()->andReturn('¡Hola! Bienvenido.');

        $mockWhatsApp = $this->mock(WhatsAppService::class);
        $mockWhatsApp->shouldReceive('sendMessage')->twice()->andReturn(false);

        $payload = $this->payloadDeTexto();

        $this->postFirmado($payload)->assertStatus(500);
        // El reintento con el mismo `messageId` se procesa otra vez porque el primero no se completó.
        $this->postFirmado($payload)->assertStatus(500);
    }

    public function test_una_excepcion_al_generar_la_respuesta_responde_500()
    {
        $this->credenciales();

        $mockGemini = $this->mock(GeminiService::class);
        $mockGemini->shouldReceive('generarRespuesta')->once()->andThrow(new \RuntimeException('Gemini caído'));

        $mockWhatsApp = $this->mock(WhatsAppService::class);
        $mockWhatsApp->shouldNotReceive('sendMessage');

        $this->postFirmado($this->payloadDeTexto())->assertStatus(500);
    }

    public function test_un_mensaje_no_textual_avisa_que_solo_se_procesa_texto()
    {
        $this->credenciales();

        $this->mock(GeminiService::class)->shouldNotReceive('generarRespuesta');

        $mockWhatsApp = $this->mock(WhatsAppService::class);
        $mockWhatsApp->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($to, $texto) => $to === '584165800403' && str_contains($texto, 'solo podemos procesar mensajes de texto'))
            ->andReturn(true);

        $payload = $this->payloadDeTexto();
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from' => '584165800403',
            'id' => 'wamid.audio.1',
            'timestamp' => '1791248186',
            'type' => 'audio',
            'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg'],
        ];

        $this->postFirmado($payload)->assertStatus(200);
    }

    public function test_un_evento_de_estado_no_falla()
    {
        $this->credenciales();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1650341340160917',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [[
                            'id' => 'wamid.12345',
                            'status' => 'failed',
                            'timestamp' => '1791248200',
                            'recipient_id' => '584165800403',
                            'errors' => [['code' => 131047, 'title' => 'Message failed']],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $response = $this->postFirmado($payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'EVENT_RECEIVED']);
    }

    /** El payload real de Meta ya no se recorta a `entry[0].changes[0]`: se recorren todos los eventos. */
    public function test_procesa_los_mensajes_de_todos_los_cambios_del_payload()
    {
        $this->credenciales();

        $mockGemini = $this->mock(GeminiService::class);
        $mockGemini->shouldReceive('generarRespuesta')->twice()->andReturn('Respuesta');

        $mockWhatsApp = $this->mock(WhatsAppService::class);
        $mockWhatsApp->shouldReceive('sendMessage')->twice()->andReturn(true);

        $payload = $this->payloadDeTexto();
        $segundo = $payload['entry'][0];
        $segundo['changes'][0]['value']['messages'][0]['id'] = 'wamid.67890';
        $segundo['changes'][0]['value']['messages'][0]['from'] = '584165800404';
        $payload['entry'][] = $segundo;

        $this->postFirmado($payload)->assertStatus(200);
    }

    // ---------------------------------------------------------------------------- Servicio de Meta

    public function test_whatsapp_service_sends_http_request_with_correct_id_and_url()
    {
        config([
            'services.whatsapp.token' => 'test_meta_token',
            'services.whatsapp.phone_number_id' => 'default_id_123',
            'services.whatsapp.version' => 'v19.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/custom_phone_id/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '584165800403', 'wa_id' => '584165800403']],
                'messages' => [['id' => 'wamid.HBg123']],
            ], 200),
        ]);

        $service = new WhatsAppService();
        $result = $service->sendMessage('+58 416 5800403', 'Texto de prueba', 'custom_phone_id');

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v19.0/custom_phone_id/messages' &&
                   $request['to'] === '584165800403' &&
                   $request['text']['body'] === 'Texto de prueba' &&
                   $request->hasHeader('Authorization', 'Bearer test_meta_token');
        });
    }

    public function test_whatsapp_service_send_template()
    {
        config([
            'services.whatsapp.token' => 'test_meta_token',
            'services.whatsapp.phone_number_id' => '1362905180231894',
            'services.whatsapp.version' => 'v19.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/1362905180231894/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.HBg999']],
            ], 200),
        ]);

        $service = new WhatsAppService();
        $response = $service->sendTemplate('0416-5800403', 'notificacion_paciente', ['José']);

        $this->assertArrayHasKey('messages', $response);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v19.0/1362905180231894/messages' &&
                   $request['to'] === '04165800403' &&
                   $request['template']['name'] === 'notificacion_paciente' &&
                   $request['template']['components'][0]['parameters'][0]['text'] === 'José';
        });
    }

    /**
     * Sin `WHATSAPP_TOKEN`/`WHATSAPP_PHONE_NUMBER_ID` en el `.env`, `config()` devuelve `null` y las
     * propiedades tipadas `string` reventaban con `TypeError` (auditoría, riesgo 6). Ahora avisan y
     * devuelven fallo.
     */
    public function test_el_servicio_sin_credenciales_no_explota()
    {
        config([
            'services.whatsapp.token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.version' => null,
        ]);

        Http::fake();

        $service = new WhatsAppService();

        $this->assertFalse($service->sendMessage('584165800403', 'Texto'));

        $resultado = $service->sendTextMessage('584165800403', 'Texto');
        $this->assertFalse($resultado['success']);

        $plantilla = $service->sendTemplate('584165800403', 'notificacion_paciente', ['José']);
        $this->assertArrayHasKey('error', $plantilla);

        Http::assertNothingSent();
    }

    /** El cliente de Gemini ya no desactiva la verificación de TLS (`Http::withoutVerifying()`). */
    public function test_gemini_no_desactiva_la_verificacion_de_tls()
    {
        $codigo = file_get_contents(app_path('Services/GeminiService.php'));

        $this->assertStringNotContainsString('withoutVerifying', $codigo);
    }

    // ----------------------------------------------------------------------------------- Ayudas

    private function credenciales(): void
    {
        config([
            'services.whatsapp.app_secret' => self::SECRETO,
            'services.whatsapp.token' => 'test_token',
            'services.whatsapp.phone_number_id' => 'id_por_defecto_111',
            'services.whatsapp.version' => 'v19.0',
        ]);
    }

    private function postFirmado(array $payload)
    {
        return $this->postJson('/api/whatsapp/webhook', $payload, [
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), self::SECRETO),
        ]);
    }

    private function payloadDeTexto(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '1650341340160917',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '584246402659',
                                    'phone_number_id' => '1362905180231894',
                                ],
                                'contacts' => [
                                    ['profile' => ['name' => 'José Rosales'], 'wa_id' => '584165800403'],
                                ],
                                'messages' => [
                                    [
                                        'from' => '584165800403',
                                        'id' => 'wamid.12345',
                                        'timestamp' => '1791248186',
                                        'text' => ['body' => 'Hola'],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];
    }
}
