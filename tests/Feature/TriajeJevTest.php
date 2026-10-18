<?php

namespace Tests\Feature;

use App\Services\JevService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La prueba de concepto de triaje con Jev AI (`php artisan jev:triaje`): que hable el **contrato real** de
 * la API, que entienda los dos sobres de respuesta y que falle con un mensaje accionable.
 *
 * Nada de esto llama a la API de verdad: `Http::fake()` fija las respuestas, así que los tests corren sin
 * `JEV_API_KEY` y sin gastar créditos.
 */
class TriajeJevTest extends TestCase
{
    private function configurar(?string $clave = 'sk_de_prueba'): void
    {
        config([
            'services.jev.api_key' => $clave,
            'services.jev.base_url' => 'https://thejevai.com',
            'services.jev.model' => 'typesafe/jev-1.14',
            'services.jev.umbral' => 0.7,
            'services.jev.timeout' => 5,
        ]);
    }

    private function respuestaOficial(string $intencion = 'agendar', float $confianza = 0.92): array
    {
        return [
            'code' => 0,
            'message' => 'ok',
            'data' => [
                'result' => [
                    'answers' => [
                        'intencion' => [
                            'type' => 'choice',
                            'choice' => $intencion,
                            'probabilities' => ['agendar' => 0.9, 'administrativo' => 0.1],
                            'confidence' => $confianza,
                        ],
                        'es_urgencia' => ['type' => 'noul', 'noul' => 0.2],
                    ],
                    'usage' => ['input_tokens' => 320, 'output_tokens' => 18],
                    'elapsedMs' => 210,
                ],
                'creditsUsed' => 1,
            ],
        ];
    }

    public function test_envia_el_contrato_real_de_systemone(): void
    {
        $this->configurar();

        Http::fake(['https://thejevai.com/v1/systemone' => Http::response($this->respuestaOficial(), 200)]);

        $resultado = app(JevService::class)->decidir('Necesito una cita', [
            'intencion' => [
                'type' => 'choice',
                'instructions' => '¿Qué quiere?',
                'criteria' => ['agendar' => 'Pide cita', 'administrativo' => 'Otra cosa'],
            ],
        ]);

        $this->assertSame('agendar', $resultado['answers']['intencion']['choice']);
        $this->assertSame(320, $resultado['usage']['input_tokens']);
        $this->assertSame(210, $resultado['elapsed_ms']);
        $this->assertSame(1, $resultado['credits']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://thejevai.com/v1/systemone'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sk_de_prueba')
                && $request['model'] === 'typesafe/jev-1.14'
                && $request['state'] === 'Necesito una cita'
                && $request['questions']['intencion']['type'] === 'choice'
                && $request['questions']['intencion']['criteria']['agendar'] === 'Pide cita';
        });
    }

    /** El proveedor de DigitalOcean devuelve `answers`/`usage` planos: el servicio tiene que entenderlo igual. */
    public function test_entiende_el_sobre_plano_de_digitalocean(): void
    {
        $this->configurar();
        config(['services.jev.base_url' => 'https://inference.do-ai.run', 'services.jev.model' => 'typesafe-jev-1.13.0']);

        Http::fake([
            'https://inference.do-ai.run/v1/systemone' => Http::response([
                'model' => 'typesafe-jev-1.13.0',
                'answers' => [
                    'intencion' => [
                        'type' => 'choice',
                        'choice' => 'cancelar',
                        'probabilities' => ['cancelar' => 1],
                        'confidence' => 1,
                    ],
                ],
                'usage' => ['input_tokens' => 200, 'output_tokens' => 20],
            ], 200),
        ]);

        $resultado = app(JevService::class)->decidir('Anulen mi cita', []);

        $this->assertSame('cancelar', $resultado['answers']['intencion']['choice']);
        $this->assertSame(200, $resultado['usage']['input_tokens']);
    }

    public function test_sin_clave_el_servicio_avisa_donde_va(): void
    {
        $this->configurar(null);

        Http::fake();

        $this->expectExceptionMessage('Falta JEV_API_KEY');

        app(JevService::class)->decidir('Hola', []);
    }

    public function test_una_clave_invalida_da_un_mensaje_accionable(): void
    {
        $this->configurar('sk_mala');

        Http::fake([
            'https://thejevai.com/v1/systemone' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->expectExceptionMessage('401');

        app(JevService::class)->decidir('Hola', []);
    }

    public function test_el_comando_sin_clave_explica_donde_ponerla(): void
    {
        $this->configurar(null);

        $codigo = \Illuminate\Support\Facades\Artisan::call('jev:triaje', ['--limite' => 1]);
        $salida = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Falta JEV_API_KEY', $salida);
        $this->assertStringContainsString('JEV_API_KEY=sk_', $salida);
    }

    public function test_el_comando_mide_aciertos_y_confianza(): void
    {
        $this->configurar();
        config(['services.jev.umbral' => 0.7]);

        $corpus = sys_get_temp_dir() . '/triaje-jev-' . uniqid() . '.json';
        file_put_contents($corpus, json_encode([
            'categorias' => [
                'agendar' => 'Pide una cita nueva',
                'administrativo' => 'Precios, horarios, sedes',
            ],
            'mensajes' => [
                ['id' => 1, 'mensaje' => 'Quiero una cita', 'intencion' => 'agendar', 'urgencia' => false],
                ['id' => 2, 'mensaje' => '¿Cuánto cuesta?', 'intencion' => 'administrativo', 'urgencia' => false],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake(function ($request) {
            $mensaje = $request['state'] ?? '';
            $intencion = str_contains($mensaje, 'cita') ? 'agendar' : 'administrativo';

            return Http::response([
                'code' => 0,
                'data' => [
                    'result' => [
                        'answers' => [
                            'intencion' => [
                                'type' => 'choice',
                                'choice' => $intencion,
                                'probabilities' => [$intencion => 0.95],
                                'confidence' => 0.95,
                            ],
                            'es_urgencia' => ['type' => 'noul', 'noul' => 0.05],
                        ],
                        'usage' => ['input_tokens' => 300, 'output_tokens' => 20],
                        'elapsedMs' => 150,
                    ],
                ],
            ], 200);
        });

        try {
            $codigo = \Illuminate\Support\Facades\Artisan::call('jev:triaje', ['--archivo' => $corpus]);
            $salida = \Illuminate\Support\Facades\Artisan::output();

            $this->assertSame(0, $codigo);
            $this->assertStringContainsString('Intención:', $salida);
            $this->assertStringContainsString('2/2', $salida);
        } finally {
            @unlink($corpus);
        }

        Http::assertSentCount(2);
    }
}
