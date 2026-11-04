<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `php artisan ia:probar`: la prueba de conectividad que usa el equipo antes de tocar el chatbot. No escribe
 * nada y no usa tools.
 */
class ProbarIACommandTest extends TestCase
{
    public function test_prueba_openrouter_y_muestra_la_respuesta(): void
    {
        config([
            'services.chatbot.proveedor' => 'openrouter',
            'services.openrouter.api_key' => 'sk-or-de-prueba',
            'services.openrouter.base_url' => 'https://openrouter.ai/api',
            'services.openrouter.model' => 'google/gemma-4-26b-a4b-it',
            'services.openrouter.timeout' => 5,
            'services.openrouter.max_tokens' => 2000,
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Hola, ¿en qué te ayudo?'],
                ]],
            ], 200),
        ]);

        $this->artisan('ia:probar', ['mensaje' => 'Hola'])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-or-de-prueba')
            && $request['model'] === 'google/gemma-4-26b-a4b-it');
    }

    public function test_sin_clave_de_openrouter_falla_con_un_mensaje_util(): void
    {
        config([
            'services.chatbot.proveedor' => 'openrouter',
            'services.openrouter.api_key' => '',
        ]);

        Http::fake();

        $this->artisan('ia:probar', ['mensaje' => 'Hola'])->assertFailed();

        Http::assertNothingSent();
    }
}
