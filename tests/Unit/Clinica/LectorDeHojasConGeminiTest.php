<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Escaneo\LecturaFallida;
use App\Clinica\Escaneo\LectorDeHojas;
use App\Clinica\Escaneo\LectorDeHojasConClaude;
use App\Clinica\Escaneo\LectorDeHojasConGemini;
use App\Clinica\Escaneo\Transcripcion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **El lector de hojas con Gemini** (WEB-13.15, el proveedor por defecto), con `Http::fake()`: qué se manda (modelo,
 * clave, archivo como `inline_data`, salida JSON con esquema) y cómo se interpreta la respuesta. No llama a Google.
 * También fija qué lector entrega el contenedor según `OCR_PROVEEDOR`.
 */
class LectorDeHojasConGeminiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function lector(array $config = []): LectorDeHojasConGemini
    {
        return new LectorDeHojasConGemini(array_merge(['api_key' => 'clave-de-prueba', 'modelo_ocr' => 'gemini-de-prueba'], $config));
    }

    private function respuesta(array $salida, string $fin = 'STOP', array $partesPrevias = []): array
    {
        return [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => array_merge($partesPrevias, [['text' => json_encode($salida, JSON_UNESCAPED_UNICODE)]])],
                'finishReason' => $fin,
            ]],
        ];
    }

    public function test_manda_el_archivo_como_inline_data_y_devuelve_el_texto_limpio(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respuesta(
            ['es_resultado' => true, 'texto' => "HEMOGLOBINA: 12,8 g/dL (ref.: 12-16)  \r\n\r\n\r\nGLUCOSA: 92 mg/dL"],
            'STOP',
            // Una parte de razonamiento: no es la respuesta.
            [['text' => 'pensando…', 'thought' => true]],
        ))]);

        $texto = $this->lector()->leer('bytes-de-la-foto', 'image/jpeg');

        $this->assertSame("HEMOGLOBINA: 12,8 g/dL (ref.: 12-16)\n\nGLUCOSA: 92 mg/dL", $texto);

        Http::assertSent(function (Request $peticion) {
            $cuerpo = $peticion->data();

            return $peticion->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-de-prueba:generateContent'
                && $peticion->hasHeader('x-goog-api-key', 'clave-de-prueba')
                && $cuerpo['systemInstruction']['parts'][0]['text'] === Transcripcion::INSTRUCCIONES
                && $cuerpo['contents'][0]['parts'][0]['inline_data'] === ['mime_type' => 'image/jpeg', 'data' => base64_encode('bytes-de-la-foto')]
                && $cuerpo['generationConfig']['responseMimeType'] === 'application/json'
                && $cuerpo['generationConfig']['responseSchema']['required'] === ['es_resultado', 'texto']
                && $cuerpo['generationConfig']['temperature'] === 0;
        });
    }

    public function test_un_pdf_viaja_con_su_tipo(): void
    {
        Http::fake(['*' => Http::response($this->respuesta(['es_resultado' => true, 'texto' => 'GLUCOSA: 92 mg/dL']))]);

        $this->lector()->leer('%PDF-1.4 x', 'application/pdf');

        Http::assertSent(fn (Request $p) => $p->data()['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'application/pdf');
    }

    public function test_lo_que_no_es_una_hoja_de_resultados_es_422(): void
    {
        Http::fake(['*' => Http::response($this->respuesta(['es_resultado' => false, 'texto' => '']))]);

        try {
            $this->lector()->leer('bytes', 'image/png');
            $this->fail('Debía rechazar lo que no es una hoja de resultados.');
        } catch (LecturaFallida $e) {
            $this->assertSame(LecturaFallida::NO_ES_RESULTADO, $e->motivo);
        }
    }

    /** @return array<string,array{respuestas:array<int,array{0:int, 1:array}>}> `[estado, cuerpo]` por intento */
    public static function fallosDelProveedor(): array
    {
        $cortada = ['candidates' => [['content' => ['parts' => [['text' => '{"es_resultado":true,"texto":"GLUC']]], 'finishReason' => 'MAX_TOKENS']]];
        $noJson = ['candidates' => [['content' => ['parts' => [['text' => 'no es JSON']]], 'finishReason' => 'STOP']]];

        return [
            'bloqueada' => ['respuestas' => [[200, ['promptFeedback' => ['blockReason' => 'SAFETY']]]]],
            'cortada' => ['respuestas' => [[200, $cortada]]],
            // Un 500 se reintenta una vez: los dos fallan.
            'error' => ['respuestas' => [[500, ['error' => ['code' => 500]]], [500, ['error' => ['code' => 500]]]]],
            'no es JSON' => ['respuestas' => [[200, $noJson]]],
        ];
    }

    #[DataProvider('fallosDelProveedor')]
    public function test_bloqueada_cortada_o_con_error_es_del_proveedor(array $respuestas): void
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

    public function test_un_429_se_reintenta_y_el_segundo_intento_lee(): void
    {
        Http::fake(['*' => Http::sequence([
            Http::response(['error' => ['code' => 429]], 429),
            Http::response($this->respuesta(['es_resultado' => true, 'texto' => 'GLUCOSA: 92 mg/dL'])),
        ])]);

        $this->assertSame('GLUCOSA: 92 mg/dL', $this->lector()->leer('bytes', 'image/png'));
        Http::assertSentCount(2);
    }

    public function test_sin_clave_no_esta_disponible_y_sin_modelo_usa_el_del_chatbot(): void
    {
        $this->assertFalse($this->lector(['api_key' => ''])->disponible());

        Http::fake(['*' => Http::response($this->respuesta(['es_resultado' => true, 'texto' => 'x']))]);
        // `GEMINI_MODELO_OCR=` en blanco llega como `''`: cae en el modelo del chatbot.
        $this->lector(['modelo_ocr' => '', 'model' => 'gemini-del-chatbot'])->leer('bytes', 'image/png');

        Http::assertSent(fn (Request $p) => str_contains($p->url(), '/models/gemini-del-chatbot:generateContent'));
    }

    public function test_el_contenedor_entrega_gemini_por_defecto_y_claude_con_anthropic(): void
    {
        config(['services.ocr.proveedor' => 'gemini']);
        $this->assertInstanceOf(LectorDeHojasConGemini::class, app(LectorDeHojas::class));

        config(['services.ocr.proveedor' => 'anthropic']);
        $this->assertInstanceOf(LectorDeHojasConClaude::class, app(LectorDeHojas::class));
    }
}
