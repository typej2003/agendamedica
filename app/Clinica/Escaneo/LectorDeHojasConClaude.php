<?php

namespace App\Clinica\Escaneo;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * **La lectura de una hoja de resultados con Claude** (WEB-13.15, la alternativa: `OCR_PROVEEDOR=anthropic`): manda la foto o el PDF y pide la
 * **transcripción literal** —no una interpretación— en texto plano, con un examen por renglón.
 *
 * Decisiones:
 *
 *  - **Las instrucciones, el esquema y la lectura de la respuesta son los de `Transcripcion`**, los mismos del
 *    lector de Gemini: cambiar de proveedor no cambia qué se transcribe. Acá va la salida estructurada como
 *    `json_schema`.
 *  - **Fallback del servidor** (`fallbacks: "default"`, beta `server-side-fallback-2026-07-01`): un rechazo por
 *    política de los clasificadores no deja al médico sin lectura. Si toda la cadena rechaza, es un error del
 *    proveedor.
 *  - **Un reintento y 90 s de espera**: el médico está esperando con la consulta abierta; si no sale, escribe a mano.
 *  - **Nada del paciente viaja** salvo la hoja misma: ni nombre, ni historia, ni consulta. Tampoco se registra el
 *    contenido: el log de un fallo lleva el motivo y no la imagen ni el texto.
 */
final class LectorDeHojasConClaude implements LectorDeHojas
{
    public const BETA_FALLBACK = 'server-side-fallback-2026-07-01';

    /** @var array{api_key:?string, modelo_ocr:string, timeout_ocr:float} */
    private array $config;

    public function __construct(?array $config = null, private ?Client $cliente = null)
    {
        $config ??= (array) config('services.anthropic', []);

        $this->config = [
            'api_key' => $config['api_key'] ?? null,
            'modelo_ocr' => (string) ($config['modelo_ocr'] ?? 'claude-opus-5-5'),
            'timeout_ocr' => (float) ($config['timeout_ocr'] ?? 90),
        ];
    }

    public function disponible(): bool
    {
        return $this->cliente !== null || trim((string) $this->config['api_key']) !== '';
    }

    public function leer(string $contenido, string $mime): string
    {
        if (! $this->disponible()) {
            throw LecturaFallida::noDisponible();
        }

        try {
            $mensaje = $this->cliente()->beta->messages->create(
                maxTokens: 16000,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        $this->bloqueDelArchivo($contenido, $mime),
                        ['type' => 'text', 'text' => Transcripcion::PEDIDO],
                    ],
                ]],
                model: $this->config['modelo_ocr'],
                fallbacks: 'default',
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => Transcripcion::ESQUEMA]],
                system: Transcripcion::INSTRUCCIONES,
                betas: [self::BETA_FALLBACK],
                requestOptions: ['timeout' => $this->config['timeout_ocr'], 'maxRetries' => 1],
            );
        } catch (AnthropicException|ClientExceptionInterface $e) {
            Log::warning('Lectura de hoja: el proveedor falló', ['error' => class_basename($e), 'codigo' => $e->getCode()]);

            throw LecturaFallida::delProveedor($e);
        }

        if ($mensaje->stopReason === 'refusal' || $mensaje->stopReason === 'max_tokens') {
            Log::warning('Lectura de hoja: respuesta incompleta', ['stop_reason' => $mensaje->stopReason]);

            throw LecturaFallida::delProveedor();
        }

        $texto = '';
        foreach ($mensaje->content as $bloque) {
            if (($bloque->type ?? null) === 'text') {
                $texto .= $bloque->text;
            }
        }

        return Transcripcion::deLaRespuesta($texto);
    }

    /** El bloque de contenido del archivo: `document` para el PDF, `image` para la foto. */
    private function bloqueDelArchivo(string $contenido, string $mime): array
    {
        $fuente = ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($contenido)];

        return ['type' => $mime === 'application/pdf' ? 'document' : 'image', 'source' => $fuente];
    }

    private function cliente(): Client
    {
        return $this->cliente ??= new Client(apiKey: (string) $this->config['api_key']);
    }
}
