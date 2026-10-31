<?php

namespace Tests\Feature;

use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\Paciente;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **El chatbot con OpenRouter** (`IA_PROVEEDOR=openrouter` o `CHATBOT_PROVEEDOR=openrouter`): mismo paciente,
 * mismo atajo, misma tool y mismo texto final que con Gemini, pero el HTTP va a OpenRouter y no a Google.
 */
class ChatbotOpenRouterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ejecuta_una_tool_y_responde_sin_tocar_gemini(): void
    {
        config([
            'services.chatbot.proveedor' => 'openrouter',
            'services.openrouter.api_key' => 'sk-or-de-prueba',
            'services.openrouter.base_url' => 'https://openrouter.ai/api',
            'services.openrouter.model' => 'google/gemma-4-26b-a4b-it',
            'services.openrouter.timeout' => 5,
            'services.openrouter.max_tokens' => 2000,
            'services.gemini.api_key' => '',
        ]);

        $medico = Medico::create([
            'name' => 'Arturo',
            'lastname' => 'Uslar',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-OR-1',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-OR-1',
            'clave' => 1,
            'especialidad' => 'Neurología',
            'ciudad' => 'Maracaibo',
            'consultorio' => 'Cons. 5',
            'linea_1' => 'Policlínica Maracaibo',
            'telefono' => '0261-7000000',
        ]);

        Paciente::create([
            'nombres' => 'Arturo',
            'apellidos' => 'Pérez',
            'telefono' => '584140001122',
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'consultar_datos_consultorio',
                                    'arguments' => json_encode(['reg_medico' => 'MED-TEST-OR-1']),
                                ],
                            ]],
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'El Dr. Arturo Uslar atiende en Policlínica Maracaibo, Consultorio 5.',
                        ],
                    ]],
                ], 200),
        ]);

        $respuesta = app(GeminiService::class)->generarRespuesta(
            '¿Qué servicios ofrecen en la clínica?',
            '584140001122'
        );

        $this->assertStringContainsString('Policlínica Maracaibo', $respuesta);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($peticion) => str_contains($peticion->url(), 'generativelanguage.googleapis.com'));
    }
}
