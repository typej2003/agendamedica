<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Escaneo\LecturaFallida;
use App\Clinica\Escaneo\LectorDeHojas;
use App\Clinica\Escaneo\LectorDeHojasConOpenRouter;
use App\Clinica\Escaneo\Transcripcion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **La lectura de hojas con OpenRouter**: qué se manda (modelo, imagen o PDF, salida estructurada) y cómo se
 * interpreta la respuesta. No sale a la red.
 */
class LectorDeHojasConOpenRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function lector(array $config = []): LectorDeHojasConOpenRouter
    {
        return new LectorDeHojasConOpenRouter(array_merge([
            'api_key' => 'sk-or-de-prueba',
            'base_url' => 'https://openrouter.ai/api',
            'modelo_ocr' => 'gemma-de-prueba',
            'timeout_ocr' => 5,
            'max_tokens_ocr' => 16000,
            'temperatura_ocr' => '0',
            'pdf_engine' => '',
            'referer' => 'https://doctorisimo.test',
            'titulo' => 'AppDDR',
        ], $config));
    }

    private function respuesta(array $salida, string $fin = 'stop'): array
    {
        return ['choices' => [[
            'finish_reason' => $fin,
            'message' => ['role' => 'assistant', 'content' => json_encode($salida, JSON_UNESCAPED_UNICODE)],
        ]]];
    }

    public function test_manda_la_foto_como_data_uri_y_devuelve_el_texto_limpio(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->respuesta(
            ['es_resultado' => true, 'texto' => "HEMOGLOBINA: 12,8 g/dL (ref.: 12-16)  \r\n\r\n\r\nGLUCOSA: 92 mg/dL"]
        ))]);

        $texto = $this->lector()->leer('bytes-de-la-foto', 'image/jpeg');

        $this->assertSame("HEMOGLOBINA: 12,8 g/dL (ref.: 12-16)\n\nGLUCOSA: 92 mg/dL", $texto);

        Http::assertSent(function (Request $peticion) {
            $cuerpo = $peticion->data();

            return $peticion->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $peticion->hasHeader('Authorization', 'Bearer sk-or-de-prueba')
                && $peticion->hasHeader('HTTP-Referer', 'https://doctorisimo.test')
                && $peticion->hasHeader('X-OpenRouter-Title', 'AppDDR')
                && $cuerpo['model'] === 'gemma-de-prueba'
                && $cuerpo['messages'][0]['content'] === Transcripcion::INSTRUCCIONES
                && $cuerpo['messages'][1]['content'][0]['text'] === Transcripcion::PEDIDO
                && $cuerpo['messages'][1]['content'][1]['image_url']['url'] === 'data:image/jpeg;base64,' . base64_encode('bytes-de-la-foto')
                && $cuerpo['response_format']['type'] === 'json_schema'
                && $cuerpo['response_format']['json_schema']['schema']['required'] === ['es_resultado', 'texto']
                && $cuerpo['temperature'] == 0
                && ! isset($cuerpo['tools']);
        });
    }

    public function test_un_pdf_viaja_como_file_y_admite_el_motor_de_parseo(): void
    {
        Http::fake(['*' => Http::response($this->respuesta(['es_resultado' => true, 'texto' => 'GLUCOSA: 92 mg/dL']))]);

        $this->lector(['pdf_engine' => 'mistral-ocr'])->leer('%PDF-1.4 x', 'application/pdf');

        Http::assertSent(function (Request $peticion) {
            $cuerpo = $peticion->data();

            return $cuerpo['messages'][1]['content'][1]['type'] === 'file'
                && $cuerpo['messages'][1]['content'][1]['file']['file_data'] === 'data:application/pdf;base64,' . base64_encode('%PDF-1.4 x')
                && $cuerpo['plugins'][0]['id'] === 'file-parser'
                && $cuerpo['plugins'][0]['pdf']['engine'] === 'mistral-ocr';
        });
    }

    public function test_sin_clave_no_esta_disponible(): void
    {
        $this->assertFalse($this->lector(['api_key' => ''])->disponible());

        $this->expectException(LecturaFallida::class);
        $this->lector(['api_key' => ''])->leer('bytes', 'image/png');
    }

    public function test_lo_que_no_es_una_hoja_de_resultados_es_422(): void
    {
        Http::fake(['*' => Http::response($this->respuesta(['es_resultado' => false, 'texto' => '']))]);

        try {
            $this->lector()->leer('bytes', 'image/png');
            $this->fail('Debía rechazar lo que no es una hoja de resultados.');
        } catch (LecturaFallida $e) {
            $this->assertSame(LecturaFallida::NO_ES_RESULTADO, $e->motivo);
            $this->assertSame(422, $e->estadoHttp());
        }
    }

    /** @return array<string,array{respuestas:array<int,array{0:int, 1:array}>}> */
    public static function fallosDelProveedor(): array
    {
        $cortada = ['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"es_resultado":true,"texto":"GLUC']]]];
        $noJson = ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'no es JSON']]]];
        $vacia = ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '']]]];

        return [
            'cortada' => ['respuestas' => [[200, $cortada]]],
            'no es JSON' => ['respuestas' => [[200, $noJson]]],
            'vacía' => ['respuestas' => [[200, $vacia]]],
            'error' => ['respuestas' => [[500, ['error' => ['code' => 500]]], [500, ['error' => ['code' => 500]]]]],
        ];
    }

    #[DataProvider('fallosDelProveedor')]
    public function test_cortada_o_con_error_es_del_proveedor(array $respuestas): void
    {
        Http::fake(['*' => Http::sequence(array_map(fn (array $r) => Http::response($r[1], $r[0]), $respuestas))]);

        try {
            $this->lector()->leer('bytes', 'image/png');
            $this->fail('Debía fallar como error del proveedor.');
        } catch (LecturaFallida $e) {
            $this->assertSame(LecturaFallida::PROVEEDOR, $e->motivo);
            $this->assertSame(502, $e->estadoHttp());
        }
    }

    public function test_el_contenedor_entrega_openrouter_con_ese_proveedor(): void
    {
        config(['services.ocr.proveedor' => 'openrouter']);

        $this->assertInstanceOf(LectorDeHojasConOpenRouter::class, app(LectorDeHojas::class));
    }
}
