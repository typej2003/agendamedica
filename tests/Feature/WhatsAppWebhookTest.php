<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    public function test_verify_webhook_success()
    {
        config(['services.whatsapp.verify_token' => 'token_de_prueba_123']);

        $response = $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=token_de_prueba_123&hub_challenge=desafio_meta');

        $response->assertStatus(200);
        $this->assertEquals('desafio_meta', $response->getContent());
    }

    public function test_verify_webhook_fails_with_invalid_token()
    {
        config(['services.whatsapp.verify_token' => 'token_de_prueba_123']);

        $response = $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=token_equivocado&hub_challenge=desafio_meta');

        $response->assertStatus(403);
    }

    public function test_handle_incoming_text_message_uses_incoming_phone_number_id()
    {
        config([
            'services.whatsapp.token' => 'test_token',
            'services.whatsapp.phone_number_id' => 'id_por_defecto_111',
            'services.whatsapp.version' => 'v19.0',
        ]);

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

        $payload = [
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
                                    [
                                        'profile' => ['name' => 'José Rosales'],
                                        'wa_id' => '584165800403',
                                    ],
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

        $response = $this->postJson('/api/whatsapp/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'EVENT_RECEIVED']);
    }

    public function test_handle_status_update_does_not_fail()
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '1650341340160917',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'statuses' => [
                                    [
                                        'id' => 'wamid.12345',
                                        'status' => 'failed',
                                        'timestamp' => '1791248200',
                                        'recipient_id' => '584165800403',
                                        'errors' => [
                                            [
                                                'code' => 131047,
                                                'title' => 'Message failed',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/whatsapp/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'EVENT_RECEIVED']);
    }

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
}
