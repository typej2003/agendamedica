<?php

namespace App\Clinica\Escaneo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * **La lectura de una hoja de resultados con Gemini** (WEB-13.15, el proveedor por defecto: `OCR_PROVEEDOR=gemini`).
 * Usa la misma clave que el chatbot de WhatsApp (`GEMINI_API_KEY`) y el mismo transporte que `GeminiService`
 * (`generateContent` de la Generative Language API con `x-goog-api-key`).
 *
 * Decisiones:
 *
 *  - **Las instrucciones y la lectura de la respuesta son las de `Transcripcion`**, las mismas que usa el lector de
 *    Claude. La foto o el PDF viajan como `inline_data` (el tope de la petición es 20 MB y el archivo llega a 10).
 *  - **Salida estructurada** con `responseMimeType: application/json` y `responseSchema` en el subconjunto OpenAPI
 *    de Gemini (tipos en mayúsculas, sin `additionalProperties`, que ese campo no acepta).
 *  - **Temperatura 0**: es una transcripción, no hay nada que variar.
 *  - **Un reintento** ante 429/5xx o una conexión caída, y 90 s de espera.
 *  - Una respuesta **bloqueada** (`promptFeedback.blockReason`) o cortada (`finishReason` distinto de `STOP`) es un
 *    error del proveedor: no se pega en la consulta un texto a medias.
 *  - ⚠️ **La clave tiene que ser de un proyecto con facturación**: en el nivel gratuito de AI Studio Google puede usar
 *    lo enviado para mejorar sus productos, y acá viajan hojas de pacientes.
 */
final class LectorDeHojasConGemini implements LectorDeHojas
{
    public const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** `Transcripcion::ESQUEMA` en el dialecto de `responseSchema`. */
    private const ESQUEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'es_resultado' => ['type' => 'BOOLEAN'],
            'texto' => ['type' => 'STRING'],
        ],
        'required' => ['es_resultado', 'texto'],
        'propertyOrdering' => ['es_resultado', 'texto'],
    ];

    /** @var array{api_key:?string, modelo_ocr:string, timeout_ocr:int} */
    private array $config;

    public function __construct(?array $config = null)
    {
        $config ??= (array) config('services.gemini', []);

        $this->config = [
            'api_key' => $config['api_key'] ?? null,
            // `?:` y no `??`: una variable del `.env` puesta en blanco llega como `''`.
            'modelo_ocr' => (string) (($config['modelo_ocr'] ?? '') ?: (($config['model'] ?? '') ?: 'gemini-3.8-flash')),
            'timeout_ocr' => (int) ($config['timeout_ocr'] ?? 90),
        ];
    }

    public function disponible(): bool
    {
        return trim((string) $this->config['api_key']) !== '';
    }

    public function leer(string $contenido, string $mime): string
    {
        if (! $this->disponible()) {
            throw LecturaFallida::noDisponible();
        }

        try {
            $respuesta = Http::timeout($this->config['timeout_ocr'])
                ->withHeaders(['x-goog-api-key' => (string) $this->config['api_key']])
                ->acceptJson()
                ->retry(2, 1000, fn (\Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())))
                ->post(sprintf(self::URL, rawurlencode($this->config['modelo_ocr'])), [
                    'systemInstruction' => ['parts' => [['text' => Transcripcion::INSTRUCCIONES]]],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($contenido)]],
                            ['text' => Transcripcion::PEDIDO],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => self::ESQUEMA,
                    ],
                ]);
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Lectura de hoja: Gemini falló', [
                'error' => class_basename($e),
                'estado' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            throw LecturaFallida::delProveedor($e);
        }

        return Transcripcion::deLaRespuesta($this->textoDe($respuesta));
    }

    /** El texto de la primera respuesta, o un error del proveedor si vino bloqueada o cortada. */
    private function textoDe(Response $respuesta): string
    {
        $bloqueo = $respuesta->json('promptFeedback.blockReason');
        $fin = $respuesta->json('candidates.0.finishReason');

        if ($bloqueo !== null || $fin !== 'STOP') {
            Log::warning('Lectura de hoja: respuesta de Gemini bloqueada o incompleta', ['bloqueo' => $bloqueo, 'fin' => $fin]);

            throw LecturaFallida::delProveedor();
        }

        // Con el pensamiento encendido, las partes de razonamiento llegan marcadas con `thought`: no son la respuesta.
        return collect((array) $respuesta->json('candidates.0.content.parts', []))
            ->reject(fn ($parte) => ! empty($parte['thought']))
            ->pluck('text')
            ->implode('');
    }
}
