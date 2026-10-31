<?php

namespace App\Services\IA;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * **Cliente de OpenRouter para el chatbot de WhatsApp** (`IA_PROVEEDOR=openrouter`). Habla el contrato de
 * *chat completions* (`POST {base}/v1/chat/completions`), que es el estable de OpenRouter para tools e imágenes.
 *
 * Decisiones:
 *
 *  - **El bucle de tools vive acá**: se manda el historial del turno, se ejecutan las tools que pida el modelo
 *    y se le devuelven los resultados hasta que redacte el texto final. El dueño de las reglas de agenda es
 *    `GeminiService`; este cliente no conoce pacientes ni consultorios.
 *  - **Una tool puede cerrar el turno** (`ResultadoDeHerramienta::$respuestaFinal`): la confirmación de una
 *    reserva o el aviso de una cita pendiente se mandan tal cual, sin pedirle al modelo que los reescriba.
 *  - **Temperatura opcional**: si `OPENROUTER_TEMPERATURA` está en blanco no se manda y rige el preset del
 *    modelo en OpenRouter (Gemma 4 trae `temperature=1`, `top_p=0.95`, `top_k=64`).
 *  - **Un reintento** ante 429/5xx o conexión caída. Al final, el chatbot cae en su mensaje de primer contacto.
 *  - **No se loguea el contenido** del mensaje ni de las tools: pueden traer datos de pacientes.
 */
final class OpenRouterService
{
    public const RUTA_CHAT = '/v1/chat/completions';

    /** @var array{api_key:string, base_url:string, model:string, timeout:int, max_tokens:int, temperatura:?float, referer:string, titulo:string} */
    private array $config;

    public function __construct(?array $config = null)
    {
        $config ??= (array) config('services.openrouter', []);

        $this->config = [
            'api_key' => trim((string) ($config['api_key'] ?? '')),
            'base_url' => rtrim((string) ($config['base_url'] ?? 'https://openrouter.ai/api'), '/'),
            'model' => (string) (($config['model'] ?? '') ?: 'google/gemma-4-26b-a4b-it'),
            'timeout' => (int) ($config['timeout'] ?? 25),
            'max_tokens' => (int) ($config['max_tokens'] ?? 2000),
            'temperatura' => is_numeric($config['temperatura'] ?? null) ? (float) $config['temperatura'] : null,
            'referer' => trim((string) ($config['referer'] ?? '')),
            'titulo' => trim((string) ($config['titulo'] ?? '')),
        ];
    }

    public function disponible(): bool
    {
        return $this->config['api_key'] !== '';
    }

    public function modelo(): string
    {
        return $this->config['model'];
    }

    /**
     * Conversa hasta obtener el texto final, ejecutando las tools que pida el modelo.
     *
     * @param  list<array{name:string,description:string,parameters:array|object}>  $herramientas
     * @param  callable(string,array<string,mixed>):ResultadoDeHerramienta  $ejecutar
     * @return string|null  el texto final; `null` si no hay clave, la API falla o el modelo no contesta
     */
    public function responder(string $sistema, string $mensaje, array $herramientas, callable $ejecutar): ?string
    {
        if (! $this->disponible()) {
            return null;
        }

        $mensajes = [
            ['role' => 'system', 'content' => $sistema],
            ['role' => 'user', 'content' => $mensaje],
        ];

        // Un turno de tool + el texto final son dos llamadas; se deja margen para una cadena corta.
        for ($turno = 0; $turno < 4; $turno++) {
            $respuesta = $this->peticion($mensajes, $herramientas);
            if ($respuesta === null) {
                return null;
            }

            $mensajeAsistente = (array) $respuesta->json('choices.0.message', []);
            $llamadas = (array) ($mensajeAsistente['tool_calls'] ?? []);

            if ($llamadas === []) {
                $texto = $this->textoDe($mensajeAsistente['content'] ?? '');

                return $texto === '' ? null : $texto;
            }

            $mensajes[] = [
                'role' => 'assistant',
                'content' => $mensajeAsistente['content'] ?? null,
                'tool_calls' => $llamadas,
            ];

            foreach ($llamadas as $llamada) {
                $nombre = (string) ($llamada['function']['name'] ?? '');
                $argumentos = json_decode((string) ($llamada['function']['arguments'] ?? '{}'), true);
                if (! is_array($argumentos)) {
                    $argumentos = [];
                }

                $resultado = $ejecutar($nombre, $argumentos);

                if ($resultado->respuestaFinal !== null) {
                    return $resultado->respuestaFinal;
                }

                $mensajes[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($llamada['id'] ?? ''),
                    'content' => (string) json_encode($resultado->datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        Log::warning('OpenRouter: se agotaron los turnos de herramientas.');

        return null;
    }

    /**
     * Una llamada al endpoint de chat completions.
     *
     * @param  list<array<string,mixed>>  $mensajes
     * @param  list<array{name:string,description:string,parameters:array|object}>  $herramientas
     */
    private function peticion(array $mensajes, array $herramientas): ?Response
    {
        $cuerpo = [
            'model' => $this->config['model'],
            'messages' => $mensajes,
            'max_tokens' => $this->config['max_tokens'],
        ];

        if ($herramientas !== []) {
            $cuerpo['tools'] = array_map(fn (array $herramienta) => [
                'type' => 'function',
                'function' => [
                    'name' => $herramienta['name'],
                    'description' => $herramienta['description'],
                    'parameters' => $herramienta['parameters'],
                ],
            ], $herramientas);
            $cuerpo['tool_choice'] = 'auto';
        }

        if ($this->config['temperatura'] !== null) {
            $cuerpo['temperature'] = $this->config['temperatura'];
        }

        try {
            return Http::timeout($this->config['timeout'])
                ->withToken($this->config['api_key'])
                ->acceptJson()
                ->withHeaders($this->cabecerasDeAplicacion())
                ->retry(2, 500, fn (\Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())))
                ->throw()
                ->post($this->config['base_url'] . self::RUTA_CHAT, $cuerpo);
        } catch (ConnectionException|RequestException $e) {
            Log::warning('OpenRouter: la petición falló.', [
                'error' => class_basename($e),
                'estado' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return null;
        }
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

    /** El contenido puede venir como texto o como partes (`[{type: text, text: …}]`). */
    private function textoDe(mixed $contenido): string
    {
        if (is_string($contenido)) {
            return trim($contenido);
        }

        if (! is_array($contenido)) {
            return '';
        }

        $partes = [];
        foreach ($contenido as $parte) {
            if (is_array($parte) && is_string($parte['text'] ?? null)) {
                $partes[] = $parte['text'];
            }
        }

        return trim(implode('', $partes));
    }
}
