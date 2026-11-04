<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **Contexto autoritativo + memoria corta del chatbot** (adaptado de DonDemand/ChatAI): la oferta pendiente y
 * los datos ya conocidos viajan en el prompt, y el turno anterior viaja como historial al modelo.
 */
class ChatbotContextoYMemoriaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'services.chatbot.proveedor' => 'openrouter',
            'services.chatbot.limite_mensajes' => 40,
            'services.chatbot.ventana_mensajes' => 300,
            'services.openrouter.api_key' => 'sk-or-de-prueba',
            'services.openrouter.base_url' => 'https://openrouter.ai/api',
            'services.openrouter.model' => 'google/gemma-4-26b-a4b-it',
            'services.openrouter.timeout' => 5,
            'services.openrouter.max_tokens' => 2000,
        ]);
    }

    public function test_el_prompt_lleva_la_oferta_pendiente_y_el_segundo_turno_lleva_el_historial(): void
    {
        $telefono = '584140001122';

        Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'telefono' => $telefono,
        ]);

        Cache::put("wa_pendiente_{$telefono}", [
            'reg_medico' => 'MED-TEST-CM-1',
            'fecha' => '2026-11-03',
            'fechas' => ['2026-11-03', '2026-11-04'],
            'reprogramar' => false,
            'anticipacion_minutos' => 1440,
        ], now()->addMinutes(30));

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push($this->texto('Hola, Ana. ¿En qué te ayudo?'), 200)
                ->push($this->texto('Sí, trabajamos con seguros. ¿Quieres agendar?'), 200),
        ]);

        $servicio = app(GeminiService::class);

        $primera = $servicio->generarRespuesta('¿Qué servicios ofrecen en la clínica?', $telefono);
        $segunda = $servicio->generarRespuesta('¿Aceptan seguros médicos?', $telefono);

        $this->assertSame('Hola, Ana. ¿En qué te ayudo?', $primera);
        $this->assertSame('Sí, trabajamos con seguros. ¿Quieres agendar?', $segunda);
        Http::assertSentCount(2);

        $peticiones = Http::recorded();
        $primeraPeticion = $peticiones[0][0]->data();
        $segundaPeticion = $peticiones[1][0]->data();

        $sistema = $primeraPeticion['messages'][0]['content'];
        $this->assertStringContainsString('ESTADO ACTUAL DEL CHAT', $sistema);
        $this->assertStringContainsString('Oferta pendiente', $sistema);
        $this->assertStringContainsString('2026-11-03', $sistema);
        $this->assertStringContainsString('Ana Pérez', $sistema);

        $this->assertSame([
            'system',
            'user',
            'assistant',
            'user',
        ], array_column($segundaPeticion['messages'], 'role'));
        $this->assertSame('¿Qué servicios ofrecen en la clínica?', $segundaPeticion['messages'][1]['content']);
        $this->assertSame('Hola, Ana. ¿En qué te ayudo?', $segundaPeticion['messages'][2]['content']);
        $this->assertSame('¿Aceptan seguros médicos?', $segundaPeticion['messages'][3]['content']);
    }

    private function texto(string $texto): array
    {
        return ['choices' => [[
            'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => $texto],
        ]]];
    }
}
