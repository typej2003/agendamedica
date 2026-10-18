<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de **Jev AI**, el modelo de **decisiones** (no de conversación): recibe un `state` y un mapa de
 * preguntas tipadas y devuelve, por cada una, un valor con **probabilidad y confianza**.
 *
 * Contrato real (verificado el 2026-10-18 en <https://thejevai.com/docs>, no en el folleto de la portada,
 * que muestra un `POST /v1/decide` con `schema` que **no** es la API):
 *
 *     POST {base_url}/v1/systemone
 *     Authorization: Bearer <JEV_API_KEY>
 *     { "model": "...", "state": "…"|{…}|[…], "questions": { "id": {…} } }
 *
 * Los tres tipos de pregunta:
 *   - `choice`: `criteria` es un mapa opción ⇒ descripción; devuelve `choice`, `probabilities` y `confidence`.
 *   - `score`:  `criteria` es un arreglo ordenado de niveles; devuelve `score`, `legend`, `probabilities`.
 *   - `noul`:   devuelve `noul` (probabilidad de que el enunciado sea cierto).
 *
 * **Dos detalles del transporte que cuestan una tarde si no se saben:**
 *  1. El sobre de la respuesta depende del proveedor: el oficial envuelve todo en
 *     `data.result.{answers,usage,elapsedMs}` (+ `data.creditsUsed`), y el de DigitalOcean devuelve
 *     `answers`/`usage` planos. `decidir()` devuelve siempre la misma forma.
 *  2. Los límites son del **request**, no del modelo: 8 preguntas y 32 KiB de JSON, con 64k tokens en total.
 *     Un `state` gigante se rechaza con 400/422, no se trunca.
 *
 * Esto es **infraestructura de la prueba de concepto** (`php artisan jev:triaje`): no está conectado al
 * chatbot de WhatsApp ni decidió nada de producción todavía.
 */
class JevService
{
    /** @var array{api_key:?string, base_url:string, model:string, umbral:float, timeout:int} */
    private array $config;

    public function __construct(?array $config = null)
    {
        $config ??= (array) config('services.jev', []);

        $this->config = [
            'api_key' => $config['api_key'] ?? null,
            'base_url' => rtrim((string) ($config['base_url'] ?? 'https://thejevai.com'), '/'),
            'model' => (string) ($config['model'] ?? 'typesafe/jev-1.14'),
            'umbral' => (float) ($config['umbral'] ?? 0.7),
            'timeout' => (int) ($config['timeout'] ?? 25),
        ];
    }

    public function tieneClave(): bool
    {
        return trim((string) $this->config['api_key']) !== '';
    }

    public function modelo(): string
    {
        return $this->config['model'];
    }

    public function umbral(): float
    {
        return $this->config['umbral'];
    }

    /**
     * Evalúa el `state` contra las preguntas y devuelve las respuestas ya desenvueltas.
     *
     * @param  string|array<int|string,mixed>  $estado
     * @param  array<string,array<string,mixed>>  $preguntas
     * @return array{answers:array<string,array<string,mixed>>,usage:array{input_tokens?:int,output_tokens?:int},elapsed_ms:?int,credits:?int,crudo:array}
     *
     * @throws RuntimeException con un mensaje accionable (sin la clave) si no hay credenciales o la API falla.
     */
    public function decidir(string|array $estado, array $preguntas): array
    {
        if (! $this->tieneClave()) {
            throw new RuntimeException(
                'Falta JEV_API_KEY en el .env de MedicAPI (ver .env.example, bloque JEV). Sin clave no hay prueba.'
            );
        }

        $cuerpo = [
            'model' => $this->config['model'],
            'state' => $estado,
            'questions' => $preguntas,
        ];

        $respuesta = $this->enviar($cuerpo);

        $json = (array) $respuesta->json();
        $respuestas = $this->respuestas($json);

        if ($respuestas === []) {
            $mensaje = $json['message'] ?? $respuesta->body();

            throw new RuntimeException('Jev respondió sin respuestas utilizables: ' . $this->recortar((string) $mensaje));
        }

        return [
            'answers' => $respuestas,
            'usage' => $this->uso($json),
            'elapsed_ms' => $this->elapsed($json),
            'credits' => $this->creditos($json),
            'crudo' => $json,
        ];
    }

    // ------------------------------------------------------------------------------------- Transporte

    private function enviar(array $cuerpo): Response
    {
        $url = $this->config['base_url'] . '/v1/systemone';
        $intentos = 0;

        while (true) {
            $intentos++;

            $respuesta = Http::withToken((string) $this->config['api_key'])
                ->timeout($this->config['timeout'])
                ->acceptJson()
                ->asJson()
                ->post($url, $cuerpo);

            // 429 (límite) y 529 (sobrecarga) se reintentan con espera; el resto es un error de la petición.
            if (in_array($respuesta->status(), [429, 529], true) && $intentos < 3) {
                usleep(500_000 * $intentos);
                continue;
            }

            if ($respuesta->successful()) {
                return $respuesta;
            }

            throw new RuntimeException($this->mensajeDeError($respuesta));
        }
    }

    private function mensajeDeError(Response $respuesta): string
    {
        $status = $respuesta->status();
        $detalle = $this->recortar((string) ($respuesta->json('message') ?? $respuesta->body()));

        return match ($status) {
            401 => 'Jev respondió 401: la clave es inválida o falta el header Authorization.',
            400, 422 => "Jev rechazó la petición ({$status}): {$detalle}. Revisá el modelo, el `state` y las preguntas.",
            429 => "Jev respondió 429 (límite de uso) después de reintentar: {$detalle}",
            529 => "Jev respondió 529 (sobrecarga) después de reintentar: {$detalle}",
            default => "Jev respondió {$status}: {$detalle}",
        };
    }

    // ---------------------------------------------------------------------------- Formas del sobre

    /** @param  array<string,mixed>  $json */
    private function respuestas(array $json): array
    {
        $candidatas = [
            $json['data']['result']['answers'] ?? null,
            $json['answers'] ?? null,
            $json['data']['answers'] ?? null,
        ];

        foreach ($candidatas as $answers) {
            if (is_array($answers) && $answers !== []) {
                return $answers;
            }
        }

        return [];
    }

    /** @param  array<string,mixed>  $json */
    private function uso(array $json): array
    {
        return (array) (
            $json['data']['result']['usage']
            ?? $json['usage']
            ?? []
        );
    }

    /** @param  array<string,mixed>  $json */
    private function elapsed(array $json): ?int
    {
        $ms = $json['data']['result']['elapsedMs'] ?? $json['elapsedMs'] ?? null;

        return $ms === null ? null : (int) $ms;
    }

    /** @param  array<string,mixed>  $json */
    private function creditos(array $json): ?int
    {
        $creditos = $json['data']['creditsUsed'] ?? null;

        return $creditos === null ? null : (int) $creditos;
    }

    private function recortar(string $texto): string
    {
        $texto = preg_replace('/\s+/', ' ', trim($texto)) ?? '';

        return mb_strlen($texto) > 300 ? mb_substr($texto, 0, 300) . '…' : $texto;
    }
}
