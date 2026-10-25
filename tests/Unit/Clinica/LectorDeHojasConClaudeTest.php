<?php

namespace Tests\Unit\Clinica;

use Anthropic\Client;
use App\Clinica\Escaneo\HojaEscaneada;
use App\Clinica\Escaneo\LecturaFallida;
use App\Clinica\Escaneo\LectorDeHojasConClaude;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * **El lector de hojas con Claude** (WEB-13.15), con el HTTP simulado: qué se manda (modelo, bloque de imagen o de
 * PDF, salida estructurada, fallback) y cómo se interpreta la respuesta. No llama al proveedor.
 */
class LectorDeHojasConClaudeTest extends TestCase
{
    /** @var array<int,array{request:\Psr\Http\Message\RequestInterface}> */
    private array $enviados = [];

    private function lector(Response ...$respuestas): LectorDeHojasConClaude
    {
        $pila = HandlerStack::create(new MockHandler($respuestas));
        $pila->push(Middleware::history($this->enviados));

        $cliente = new Client(apiKey: 'clave-de-prueba', requestOptions: [
            'transporter' => new Guzzle(['handler' => $pila]),
            'maxRetries' => 0,
        ]);

        return new LectorDeHojasConClaude(['api_key' => null, 'modelo_ocr' => 'claude-opus-5-5'], $cliente);
    }

    private function respuesta(array $salida, string $stopReason = 'end_turn'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_prueba',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($salida, JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ]));
    }

    private function cuerpoEnviado(): array
    {
        return json_decode((string) $this->enviados[0]['request']->getBody(), true);
    }

    public function test_manda_la_imagen_con_su_tipo_y_devuelve_el_texto_limpio(): void
    {
        $lector = $this->lector($this->respuesta([
            'es_resultado' => true,
            'texto' => "HEMATOLOGÍA  \r\nHEMOGLOBINA: 12,8 g/dL (ref.: 12-16)\r\n\r\n\r\n\r\nQUÍMICA\n",
        ]));

        $texto = $lector->leer('bytes-de-la-foto', 'image/jpeg');

        $this->assertSame("HEMATOLOGÍA\nHEMOGLOBINA: 12,8 g/dL (ref.: 12-16)\n\nQUÍMICA", $texto);

        $peticion = $this->enviados[0]['request'];
        $this->assertStringEndsWith('/v1/messages', $peticion->getUri()->getPath());
        $this->assertStringContainsString(LectorDeHojasConClaude::BETA_FALLBACK, $peticion->getHeaderLine('anthropic-beta'));

        $cuerpo = $this->cuerpoEnviado();
        $this->assertSame('claude-opus-5-5', $cuerpo['model']);
        $this->assertSame('default', $cuerpo['fallbacks']);
        $this->assertSame('json_schema', $cuerpo['output_config']['format']['type']);
        $this->assertArrayNotHasKey('thinking', $cuerpo, 'Claude Opus 5.5 no acepta apagar el pensamiento: no se manda.');

        $archivo = $cuerpo['messages'][0]['content'][0];
        $this->assertSame('image', $archivo['type']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode('bytes-de-la-foto')], $archivo['source']);
    }

    public function test_un_pdf_viaja_como_documento(): void
    {
        $lector = $this->lector($this->respuesta(['es_resultado' => true, 'texto' => 'GLUCOSA: 92 mg/dL']));

        $lector->leer('%PDF-1.4 algo', 'application/pdf');

        $archivo = $this->cuerpoEnviado()['messages'][0]['content'][0];
        $this->assertSame('document', $archivo['type']);
        $this->assertSame('application/pdf', $archivo['source']['media_type']);
    }

    public function test_lo_que_no_es_una_hoja_de_resultados_no_se_pega(): void
    {
        $lector = $this->lector($this->respuesta(['es_resultado' => false, 'texto' => 'Una foto de un perro']));

        try {
            $lector->leer('bytes', 'image/png');
            $this->fail('Debía rechazar lo que no es una hoja de resultados.');
        } catch (LecturaFallida $e) {
            $this->assertSame(LecturaFallida::NO_ES_RESULTADO, $e->motivo);
            $this->assertSame(422, $e->estadoHttp());
        }
    }

    public function test_un_rechazo_o_un_error_del_proveedor_es_502(): void
    {
        $error = fn () => new Response(500, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"api_error","message":"x"}}');

        foreach ([
            [$this->respuesta(['es_resultado' => true, 'texto' => 'x'], 'refusal')],
            // Un 500 se reintenta una vez (`maxRetries: 1`): el segundo también falla.
            [$error(), $error()],
        ] as $respuestas) {
            $this->enviados = [];

            try {
                $this->lector(...$respuestas)->leer('bytes', 'image/png');
                $this->fail('Debía fallar como error del proveedor.');
            } catch (LecturaFallida $e) {
                $this->assertSame(LecturaFallida::PROVEEDOR, $e->motivo);
                $this->assertSame(502, $e->estadoHttp());
            }
        }
    }

    public function test_sin_clave_no_esta_disponible(): void
    {
        $lector = new LectorDeHojasConClaude(['api_key' => '']);

        $this->assertFalse($lector->disponible());
        $this->expectExceptionObject(LecturaFallida::noDisponible());
        $lector->leer('bytes', 'image/png');
    }

    public function test_el_tipo_se_decide_por_el_contenido(): void
    {
        $this->assertSame('application/pdf', HojaEscaneada::tipo("%PDF-1.7\n"));
        $this->assertSame('image/png', HojaEscaneada::tipo("\x89PNG\r\n\x1A\n" . str_repeat("\0", 8)));
        $this->assertSame('image/jpeg', HojaEscaneada::tipo("\xFF\xD8\xFF\xE0" . str_repeat("\0", 12)));
        $this->assertNull(HojaEscaneada::tipo('texto plano que se llama .jpg'));
        // HEIC: el proveedor no lo lee.
        $this->assertNull(HojaEscaneada::tipo("\0\0\0\x18ftypheic\0\0\0\0mif1heic"));
    }

    public function test_la_foto_grande_viaja_reducida_y_la_chica_y_el_pdf_tal_cual(): void
    {
        $lienzo = imagecreatetruecolor(4000, 3000);
        ob_start();
        imagepng($lienzo);
        $grande = (string) ob_get_clean();

        [$reducida, $mime] = HojaEscaneada::paraLeer($grande, 'image/png');
        $medidas = getimagesizefromstring($reducida);
        $this->assertSame('image/jpeg', $mime);
        $this->assertSame([HojaEscaneada::LADO_MAXIMO, 1800], [$medidas[0], $medidas[1]]);

        $chica = imagecreatetruecolor(800, 600);
        ob_start();
        imagepng($chica);
        $png = (string) ob_get_clean();
        $this->assertSame([$png, 'image/png'], HojaEscaneada::paraLeer($png, 'image/png'));

        $this->assertSame(['%PDF-1.4 x', 'application/pdf'], HojaEscaneada::paraLeer('%PDF-1.4 x', 'application/pdf'));
    }
}
