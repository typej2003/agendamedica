<?php

namespace Tests\Unit\Services;

use App\Services\IA\OpenRouterService;
use App\Services\IA\ResultadoDeHerramienta;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * **El cliente de OpenRouter para el chatbot**: qué manda (modelo, clave, cabeceras, tools), cómo ejecuta una
 * tool y cómo cierra el turno. No sale a la red: `Http::fake()` fija las respuestas.
 */
class OpenRouterServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function servicio(array $config = []): OpenRouterService
    {
        return new OpenRouterService(array_merge([
            'api_key' => 'sk-or-de-prueba',
            'base_url' => 'https://openrouter.ai/api',
            'model' => 'google/gemma-4-26b-a4b-it',
            'timeout' => 5,
            'max_tokens' => 2000,
            'temperatura' => null,
            'referer' => 'https://doctorisimo.test',
            'titulo' => 'AppDDR',
        ], $config));
    }

    private function texto(string $texto): array
    {
        return ['choices' => [[
            'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => $texto],
        ]]];
    }

    private function llamada(string $nombre = 'consultar', string $argumentos = '{"reg_medico":"MED-1"}'): array
    {
        return ['choices' => [[
            'finish_reason' => 'tool_calls',
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => $nombre, 'arguments' => $argumentos],
                ]],
            ],
        ]]];
    }

    public function test_sin_clave_no_esta_disponible(): void
    {
        $this->assertFalse($this->servicio(['api_key' => ''])->disponible());
        $this->assertNull($this->servicio(['api_key' => ''])->responder('S', 'U', [], fn () => new ResultadoDeHerramienta()));
    }

    public function test_manda_el_modelo_las_cabeceras_y_no_manda_temperatura_sin_configurar(): void
    {
        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response($this->texto('Hola, ¿en qué te ayudo?'))]);

        $texto = $this->servicio()->responder('Eres un asistente.', 'Hola', [], fn () => new ResultadoDeHerramienta());

        $this->assertSame('Hola, ¿en qué te ayudo?', $texto);

        Http::assertSent(function (Request $peticion) {
            $cuerpo = $peticion->data();

            return $peticion->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $peticion->hasHeader('Authorization', 'Bearer sk-or-de-prueba')
                && $peticion->hasHeader('HTTP-Referer', 'https://doctorisimo.test')
                && $peticion->hasHeader('X-OpenRouter-Title', 'AppDDR')
                && $cuerpo['model'] === 'google/gemma-4-26b-a4b-it'
                && $cuerpo['messages'][0] === ['role' => 'system', 'content' => 'Eres un asistente.']
                && $cuerpo['messages'][1] === ['role' => 'user', 'content' => 'Hola']
                && ! isset($cuerpo['tools'])
                && ! isset($cuerpo['temperature']);
        });
    }

    public function test_si_hay_temperatura_configurada_la_manda(): void
    {
        Http::fake(['*' => Http::response($this->texto('ok'))]);

        $this->servicio(['temperatura' => '0.2'])->responder('S', 'U', [], fn () => new ResultadoDeHerramienta());

        Http::assertSent(fn (Request $p) => $p->data()['temperature'] === 0.2);
    }

    public function test_ejecuta_la_tool_y_devuelve_el_texto_final(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push($this->llamada(), 200)
                ->push($this->texto('La agenda tiene lugar el lunes.'), 200),
        ]);

        $ejecutadas = [];

        $texto = $this->servicio()->responder(
            'Sistema',
            '¿Hay cupo?',
            [[
                'name' => 'consultar',
                'description' => 'Consulta la agenda.',
                'parameters' => ['type' => 'object', 'properties' => ['reg_medico' => ['type' => 'string']]],
            ]],
            function (string $nombre, array $argumentos) use (&$ejecutadas): ResultadoDeHerramienta {
                $ejecutadas[] = [$nombre, $argumentos];

                return new ResultadoDeHerramienta(['opciones' => ['2026-11-02']]);
            }
        );

        $this->assertSame('La agenda tiene lugar el lunes.', $texto);
        $this->assertSame([['consultar', ['reg_medico' => 'MED-1']]], $ejecutadas);
        Http::assertSentCount(2);

        $grabadas = Http::recorded();
        $primera = $grabadas[0][0]->data();
        $segunda = $grabadas[1][0]->data();

        $this->assertSame('function', $primera['tools'][0]['type'] ?? null);
        $this->assertSame('consultar', $primera['tools'][0]['function']['name'] ?? null);
        $this->assertSame('auto', $primera['tool_choice'] ?? null);
        $this->assertSame('user', end($primera['messages'])['role']);

        $assistant = $segunda['messages'][2];
        $tool = $segunda['messages'][3];

        $this->assertSame('assistant', $assistant['role']);
        $this->assertSame('call_1', $assistant['tool_calls'][0]['id']);
        $this->assertSame('tool', $tool['role']);
        $this->assertSame('call_1', $tool['tool_call_id']);
        $this->assertSame(['opciones' => ['2026-11-02']], json_decode($tool['content'], true));
    }

    public function test_una_tool_puede_cerrar_el_turno_sin_segunda_llamada(): void
    {
        Http::fake(['*' => Http::response($this->llamada('reservar_cita'))]);

        $texto = $this->servicio()->responder(
            'S',
            'Reserva',
            [['name' => 'reservar_cita', 'description' => 'Reserva.', 'parameters' => ['type' => 'object']]],
            fn () => new ResultadoDeHerramienta([], 'Tu cita quedó confirmada.')
        );

        $this->assertSame('Tu cita quedó confirmada.', $texto);
        Http::assertSentCount(1);
    }

    public function test_un_error_del_proveedor_devuelve_null(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 500]], 500)
            ->push(['error' => ['code' => 500]], 500)]);

        $this->assertNull($this->servicio()->responder('S', 'U', [], fn () => new ResultadoDeHerramienta()));
        Http::assertSentCount(2);
    }
}
