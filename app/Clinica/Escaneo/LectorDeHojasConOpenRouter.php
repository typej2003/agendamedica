<?php

namespace App\Clinica\Escaneo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * **La lectura de una hoja de resultados con OpenRouter** (`OCR_PROVEEDOR=openrouter`, o `IA_PROVEEDOR=openrouter`).
 * Usa la misma `OPENROUTER_API_KEY` que el chatbot y Jev, y el contrato de *chat completions*.
 *
 * Decisiones:
 *
 *  - **Las instrucciones, el esquema y la lectura de la respuesta son los de `Transcripcion`**, los mismos que
 *    los lectores de Gemini y Claude: cambiar de proveedor no cambia qué se transcribe.
 *  - **Salida estructurada** con `response_format: json_schema` (`es_resultado` + `texto`). El modelo por
 *    defecto, Gemma 4 26B A4B, soporta `structured_outputs` y visión.
 *  - **La foto viaja como `image_url` con data URI**; el PDF como parte `file` (`file_data`), que OpenRouter
 *    parsea aunque el modelo no tenga entrada de archivos nativa. Con `OPENROUTER_PDF_ENGINE` se elige el motor
 *    (`mistral-ocr` para escaneados, `cloudflare-ai` para PDF con texto, `native` si el modelo lo soporta).
 *  - **Temperatura 0 por defecto** (`OPENROUTER_TEMPERATURA_OCR`): es una transcripción, no hay nada que variar.
 *  - **Un reintento** ante 429/5xx o una conexión caída, y 90 s de espera.
 *  - Una respuesta cortada (`finish_reason` `length`/`content_filter`/`error`) es un error del proveedor: no se
 *    pega en la consulta un texto a medias. El log guarda el motivo, nunca el contenido de la hoja.
 */
final class LectorDeHojasConOpenRouter implements LectorDeHojas
{
    public const RUTA = '/v1/chat/completions';

    /** @var array{api_key:string, base_url:string, modelo_ocr:string, timeout_ocr:int, max_tokens_ocr:int, temperatura_ocr:?float, pdf_engine:string, referer:string, titulo:string} */
    private array $config;

    public function __construct(?array $config = null)
    {
        $config ??= (array) config('services.openrouter', []);

        $this->config = [
            'api_key' => trim((string) ($config['api_key'] ?? '')),
            'base_url' => rtrim((string) ($config['base_url'] ?? 'https://openrouter.ai/api'), '/'),
            // `?:` y no `??`: una variable del `.env` puesta en blanco llega como `''`.
            'modelo_ocr' => (string) (($config['modelo_ocr'] ?? '') ?: (($config['model'] ?? '') ?: 'google/gemma-4-26b-a4b-it')),
            'timeout_ocr' => (int) ($config['timeout_ocr'] ?? 90),
            'max_tokens_ocr' => (int) ($config['max_tokens_ocr'] ?? 16000),
            'temperatura_ocr' => is_numeric($config['temperatura_ocr'] ?? null) ? (float) $config['temperatura_ocr'] : null,
            'pdf_engine' => trim((string) ($config['pdf_engine'] ?? '')),
            'referer' => trim((string) ($config['referer'] ?? '')),
            'titulo' => trim((string) ($config['titulo'] ?? '')),
        ];
    }

    public function disponible(): bool
    {
        return $this->config['api_key'] !== '';
    }

    public function leer(string $contenido, string $mime): string
    {
        if (! $this->disponible()) {
            throw LecturaFallida::noDisponible();
        }

        $cuerpo = [
            'model' => $this->config['modelo_ocr'],
            'messages' => [
                ['role' => 'system', 'content' => Transcripcion::INSTRUCCIONES],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => Transcripcion::PEDIDO],
                    $this->parteDelArchivo($contenido, $mime),
                ]],
            ],
            'max_tokens' => $this->config['max_tokens_ocr'],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'transcripcion',
                    'strict' => true,
                    'schema' => Transcripcion::ESQUEMA,
                ],
            ],
        ];

        if ($this->config['temperatura_ocr'] !== null) {
            $cuerpo['temperature'] = $this->config['temperatura_ocr'];
        }

        if ($mime === 'application/pdf' && $this->config['pdf_engine'] !== '') {
            $cuerpo['plugins'] = [[
                'id' => 'file-parser',
                'pdf' => ['engine' => $this->config['pdf_engine']],
            ]];
        }

        try {
            $respuesta = Http::timeout($this->config['timeout_ocr'])
                ->withToken($this->config['api_key'])
                ->acceptJson()
                ->withHeaders($this->cabecerasDeAplicacion())
                ->retry(2, 1000, fn (\Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())))
                ->throw()
                ->post($this->config['base_url'] . self::RUTA, $cuerpo);
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Lectura de hoja: OpenRouter falló', [
                'error' => class_basename($e),
                'estado' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            throw LecturaFallida::delProveedor($e);
        }

        $fin = $respuesta->json('choices.0.finish_reason');
        if (in_array($fin, ['length', 'content_filter', 'error'], true)) {
            Log::warning('Lectura de hoja: respuesta de OpenRouter incompleta', ['fin' => $fin]);

            throw LecturaFallida::delProveedor();
        }

        $texto = $respuesta->json('choices.0.message.content');
        if (! is_string($texto) || trim($texto) === '') {
            Log::warning('Lectura de hoja: OpenRouter no devolvió texto', ['fin' => $fin]);

            throw LecturaFallida::delProveedor();
        }

        return Transcripcion::deLaRespuesta($texto);
    }

    /** El archivo en el formato de chat completions: `file` para el PDF, `image_url` para la foto. */
    private function parteDelArchivo(string $contenido, string $mime): array
    {
        $datos = base64_encode($contenido);

        if ($mime === 'application/pdf') {
            return [
                'type' => 'file',
                'file' => [
                    'filename' => 'hoja.pdf',
                    'file_data' => 'data:application/pdf;base64,' . $datos,
                ],
            ];
        }

        return [
            'type' => 'image_url',
            'image_url' => ['url' => "data:{$mime};base64,{$datos}"],
        ];
    }

    /** @return array<string,string> */
    private function cabecerasDeAplicacion(): array
    {
        $cabeceras = [];

        if ($this->config['referer'] !== '') {
            $cabeceras['HTTP-Referer'] = $this->config['referer'];
        }

        if ($this->config['titulo'] !== '') {
            $cabeceras['X-OpenRouter-Title'] = $this->config['titulo'];
        }

        return $cabeceras;
    }
}
