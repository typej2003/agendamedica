<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **Guardas pre-LLM del chatbot**: el off-topic no gasta tokens y el tope por teléfono corta la ráfaga.
 */
class ChatbotGuardasTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'services.chatbot.proveedor' => 'openrouter',
            'services.openrouter.api_key' => 'sk-or-de-prueba',
            'services.openrouter.base_url' => 'https://openrouter.ai/api',
            'services.openrouter.model' => 'google/gemma-4-26b-a4b-it',
        ]);
    }

    public function test_un_mensaje_fuera_de_tema_no_llama_al_modelo(): void
    {
        Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'telefono' => '584140001122',
        ]);

        Http::fake();

        $respuesta = app(GeminiService::class)->generarRespuesta('Cuánto es 8x5?', '584140001122');

        $this->assertStringContainsString('sólo puedo ayudarte con citas', $respuesta);
        Http::assertNothingSent();
    }

    public function test_el_tope_por_telefono_corta_la_rafaga(): void
    {
        config(['services.chatbot.limite_mensajes' => 2]);

        Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'telefono' => '584140001122',
        ]);

        Http::fake();
        $servicio = app(GeminiService::class);

        $primera = $servicio->generarRespuesta('Hola', '584140001122');
        $segunda = $servicio->generarRespuesta('Hola', '584140001122');
        $tercera = $servicio->generarRespuesta('Hola', '584140001122');

        $this->assertStringContainsString('Doctorisimo', $primera);
        $this->assertStringContainsString('Doctorisimo', $segunda);
        $this->assertStringContainsString('muchos mensajes', $tercera);
        Http::assertNothingSent();
    }
}
