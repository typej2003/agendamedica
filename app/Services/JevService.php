<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de **Jev AI**, el modelo de **decisiones** (no de conversación): recibe un estado y preguntas
 * tipadas y devuelve, por cada una, un valor con **probabilidad y confianza**.
 *
 * Hay **dos transportes** y no dan lo mismo (medido el 2026-10-18):
 *
 *  1. **`systemone`** — el protocolo propio de Jev: `POST {base}/v1/systemone` con `state` + `questions`
 *     (`choice` / `score` / `noul`). Devuelve `probabilities` **calibradas** y `confidence` del modelo, y la
 *     salida es gratis. Es el único que sirve para calibrar un umbral de derivación con fundamento.
 *     Lo exponen el Jev oficial (`https://thejevai.com`) y DigitalOcean Inference.
 *  2. **`chat`** — el camino degradado por los agregadores que sólo hablan OpenAI: `POST {base}/v1/chat/
 *     completions` con un prompt que exige un JSON. Devuelve texto, y la **confianza pasa a ser
 *     autoinformada** por el modelo (no calibrada): sirve para saber si acierta la categoría, **no** para
 *     fijar un umbral en producción. **DefAPI (defapi.org) es de estos**: su API no tiene `/v1/systemone`
 *     ni `/v1/decide`, sólo `/v1/chat/completions` y `/v1/messages` (comprobado el 2026-10-18; su catálogo
 *     igual lista `typesafe/jev-1.13` a $0,084/MTok).
 *
 * `auto` intenta `systemone` y, si el servidor no tiene esa ruta (404/405), sigue por `chat`. `auto` es el
 * default justamente para que la prueba funcione en los dos proveedores, pero el comando **dice cuál usó**.
 *
 * Con `systemone` el sobre también varía por proveedor: el oficial envuelve en
 * `data.result.{answers,usage,elapsedMs}`, DigitalOcean devuelve `answers`/`usage` planos. Acá se normaliza.
 *
 * Límites del `systemone` (del request, no del modelo): 8 preguntas, 32 KiB de JSON, 64k tokens en total.
 *
 * Esto es **infraestructura de la prueba de concepto** (`php artisan jev:triaje`): no está conectado al
 * chatbot de WhatsApp ni decidió nada de producción todavía.
 */
class JevService
{
    /**
     * Las rutas del protocolo **de decisiones** de Jev (las que devuelven probabilidades calibradas).
     *
     * `systemone` es la del Jev oficial y la de DigitalOcean; `decisions` es la de DefAPI, que monta el
     * servicio en `/api/v1/decisions` con **el mismo cuerpo** (`model` + `state` + `questions`) y el mismo
     * sobre plano que DigitalOcean (medido el 2026-10-18 con la clave real).
     */
    private const RUTAS_DE_DECISION = [
        'systemone' => '/v1/systemone',
        'decisions' => '/api/v1/decisions',
    ];

    /** @var array{api_key:?string, base_url:string, model:string, protocolo:string, umbral:float, timeout:int, intentos:int} */
    private array $config;

    private ?string $protocoloUsado = null;

    public function __construct(?array $config = null)
    {
        $config ??= (array) config('services.jev', []);

        $this->config = [
            'api_key' => $config['api_key'] ?? null,
            'base_url' => rtrim((string) ($config['base_url'] ?? 'https://thejevai.com'), '/'),
            'model' => (string) ($config['model'] ?? 'typesafe/jev-1.14'),
            'protocolo' => (string) ($config['protocolo'] ?? 'auto'),
            'umbral' => (float) ($config['umbral'] ?? 0.7),
            'timeout' => (int) ($config['timeout'] ?? 25),
            // Intentos máximos ante 429/529. El uso en línea (el bot) lo baja a 1: sin reintentos ni esperas.
            'intentos' => max(1, (int) ($config['intentos'] ?? 3)),
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

    /** `systemone` o `chat`: cuál se usó de verdad en la última llamada (`auto` se resuelve acá). */
    public function protocoloUsado(): ?string
    {
        return $this->protocoloUsado;
    }

    /** La confianza de este transporte, ¿es del modelo (calibrada) o autoinformada? */
    public function confianzaCalibrada(): bool
    {
        return $this->protocoloUsado !== 'chat';
    }

    /**
     * Evalúa el `state` contra las preguntas y devuelve las respuestas ya normalizadas.
     *
     * @param  string|array<int|string,mixed>  $estado
     * @param  array<string,array<string,mixed>>  $preguntas
     * @return array{answers:array<string,array<string,mixed>>,usage:array<string,mixed>,elapsed_ms:?int,credits:?int,costo:?float,crudo:array}
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

        $protocolo = $this->config['protocolo'];

        if ($protocolo === 'chat') {
            return $this->decidirPorChat($estado, $preguntas);
        }

        if (isset(self::RUTAS_DE_DECISION[$protocolo])) {
            return $this->decidirPorDecisiones($protocolo, $estado, $preguntas);
        }

        // `auto`: las rutas de decisión en orden y, si el proveedor no tiene ninguna, el camino de chat.
        foreach (array_keys(self::RUTAS_DE_DECISION) as $candidato) {
            try {
                return $this->decidirPorDecisiones($candidato, $estado, $preguntas);
            } catch (JevSinRutaDeDecision $e) {
                continue;
            }
        }

        return $this->decidirPorChat($estado, $preguntas);
    }

    // ------------------------------------------------------------------------------- Protocolo propio

    /**
     * `POST {ruta}` con `model` + `state` + `questions`: el protocolo de Jev, con preguntas tipadas y
     * probabilidades **calibradas**. Devuelve `answers` con `probabilities`/`confidence` por pregunta.
     */
    private function decidirPorDecisiones(string $protocolo, string|array $estado, array $preguntas): array
    {
        $inicio = microtime(true);

        $respuesta = $this->enviar($this->config['base_url'] . self::RUTAS_DE_DECISION[$protocolo], [
            'model' => $this->config['model'],
            'state' => $estado,
            'questions' => $preguntas,
        ]);

        $json = (array) $respuesta->json();
        $respuestas = $this->respuestas($json);

        if ($respuestas === []) {
            $mensaje = $json['message'] ?? $respuesta->body();

            throw new RuntimeException('Jev respondió sin respuestas utilizables: ' . $this->recortar((string) $mensaje));
        }

        $this->protocoloUsado = $protocolo;

        return [
            'answers' => $respuestas,
            'usage' => $this->uso($json),
            // DefAPI no informa `elapsedMs`: se mide acá para no reportar 0 ms.
            'elapsed_ms' => $this->elapsed($json) ?? (int) round((microtime(true) - $inicio) * 1000),
            'credits' => $this->creditos($json),
            'costo' => $this->costo($json),
            'crudo' => $json,
        ];
    }

    // --------------------------------------------------------------------------------- Camino de chat

    /**
     * El camino degradado: un `chat/completions` que pide un JSON con la misma forma de `answers`.
     *
     * La confianza que devuelve es **autoinformada**: el modelo dice cuánto confía, en vez de repartir
     * probabilidad entre las opciones. Para comparar categorías sirve; para fijar un umbral de derivación,
     * hay que tratarla como una señal más y no como la calibrada de `systemone`.
     */
    private function decidirPorChat(string|array $estado, array $preguntas): array
    {
        $inicio = microtime(true);

        $respuesta = $this->enviar(
            $this->config['base_url'] . '/v1/chat/completions',
            [
                'model' => $this->config['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->instruccionesParaChat($preguntas)],
                    ['role' => 'user', 'content' => "Estado a evaluar:\n" . $this->estadoEnTexto($estado)],
                ],
                // Clasificación: sin creatividad. No es el muestreo de un modelo local de chat, es fijar la
                // salida de un clasificador.
                'temperature' => 0,
            ],
            [404, 405],
        );

        $json = (array) $respuesta->json();
        $texto = (string) ($json['choices'][0]['message']['content'] ?? '');
        $respuestas = $this->respuestasDelJsonDeChat($texto, $preguntas);

        if ($respuestas === []) {
            throw new RuntimeException(
                'El proveedor respondió en modo chat, pero sin un JSON utilizable: ' . $this->recortar($texto)
            );
        }

        $this->protocoloUsado = 'chat';

        return [
            'answers' => $respuestas,
            'usage' => $this->uso($json),
            'elapsed_ms' => (int) round((microtime(true) - $inicio) * 1000),
            'credits' => $this->creditos($json),
            'costo' => $this->costo($json),
            'crudo' => $json,
        ];
    }

    /**
     * Las instrucciones para el modo chat: los criterios de las preguntas, el formato exacto del JSON y la
     * regla de contestar **sólo** el JSON.
     *
     * @param  array<string,array<string,mixed>>  $preguntas
     */
    private function instruccionesParaChat(array $preguntas): string
    {
        $lineas = [
            'Eres un clasificador. Respondes ÚNICAMENTE con un objeto JSON válido, sin texto antes ni después, sin bloques de código.',
            'El JSON tiene que tener una clave por cada pregunta y, en cada una, exactamente los campos pedidos.',
        ];

        foreach ($preguntas as $id => $pregunta) {
            $tipo = $pregunta['type'] ?? '';

            $lineas[] = '';
            $lineas[] = "Pregunta `{$id}`:";

            $instrucciones = $pregunta['instructions'] ?? '';
            $lineas[] = is_string($instrucciones) ? $instrucciones : json_encode($instrucciones, JSON_UNESCAPED_UNICODE);

            if ($tipo === 'choice') {
                $lineas[] = 'Elige exactamente una de estas opciones:';
                foreach ((array) ($pregunta['criteria'] ?? []) as $opcion => $descripcion) {
                    $lineas[] = '- ' . $opcion . ': ' . (is_string($descripcion) ? $descripcion : json_encode($descripcion, JSON_UNESCAPED_UNICODE));
                }
                $lineas[] = "Devolvé: {\"{$id}\": {\"choice\": \"<una de las opciones>\", \"confidence\": <0 a 1>}}";
            } elseif ($tipo === 'noul') {
                $lineas[] = "Devolvé: {\"{$id}\": {\"noul\": <0 a 1, probabilidad de que sea cierto>}}";
            } elseif ($tipo === 'score') {
                $lineas[] = 'Niveles, de menor a mayor: ' . json_encode($pregunta['criteria'] ?? [], JSON_UNESCAPED_UNICODE);
                $lineas[] = "Devolvé: {\"{$id}\": {\"score\": <número>, \"confidence\": <0 a 1>}}";
            }
        }

        $lineas[] = '';
        $lineas[] = 'No expliques nada. Si el estado no alcanza para decidir, elige la opción más probable y baja la confianza.';

        return implode("\n", $lineas);
    }

    /** @param  string|array<int|string,mixed>  $estado */
    private function estadoEnTexto(string|array $estado): string
    {
        return is_string($estado) ? $estado : (string) json_encode($estado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Lee el JSON del mensaje y lo vuelve a la forma de `answers` del protocolo propio.
     *
     * @param  array<string,array<string,mixed>>  $preguntas
     * @return array<string,array<string,mixed>>
     */
    private function respuestasDelJsonDeChat(string $texto, array $preguntas): array
    {
        $texto = trim($texto);

        // Los modelos suelen envolverlo en ```json … ```; se recorta a las llaves si hace falta.
        $inicio = strpos($texto, '{');
        $fin = strrpos($texto, '}');
        if ($inicio !== false && $fin !== false && $fin > $inicio) {
            $texto = substr($texto, $inicio, $fin - $inicio + 1);
        }

        $datos = json_decode($texto, true);
        if (! is_array($datos)) {
            return [];
        }

        $respuestas = [];

        foreach ($preguntas as $id => $pregunta) {
            $valor = $datos[$id] ?? null;
            $tipo = $pregunta['type'] ?? '';

            if ($tipo === 'choice') {
                $eleccion = is_array($valor) ? ($valor['choice'] ?? null) : $valor;
                if (! is_string($eleccion) || $eleccion === '') {
                    continue;
                }

                $confianza = is_array($valor) && isset($valor['confidence']) ? (float) $valor['confidence'] : 0.0;
                $confianza = max(0.0, min(1.0, $confianza));

                $respuestas[$id] = [
                    'type' => 'choice',
                    'choice' => $eleccion,
                    'probabilities' => is_array($valor) && is_array($valor['probabilities'] ?? null)
                        ? $valor['probabilities']
                        : [$eleccion => $confianza],
                    'confidence' => $confianza,
                ];
            } elseif ($tipo === 'noul') {
                $noul = is_array($valor) ? ($valor['noul'] ?? null) : $valor;
                if (! is_numeric($noul)) {
                    continue;
                }

                $respuestas[$id] = ['type' => 'noul', 'noul' => max(0.0, min(1.0, (float) $noul))];
            } elseif ($tipo === 'score' && is_array($valor) && is_numeric($valor['score'] ?? null)) {
                $respuestas[$id] = [
                    'type' => 'score',
                    'score' => (float) $valor['score'],
                    'confidence' => isset($valor['confidence']) ? max(0.0, min(1.0, (float) $valor['confidence'])) : 0.0,
                ];
            }
        }

        return $respuestas;
    }

    // ------------------------------------------------------------------------------------- Transporte

    /** @param  array<int,int>  $reintentarEn  estados que sí valen un reintento con espera */
    private function enviar(string $url, array $cuerpo, array $reintentarEn = [429, 529]): Response
    {
        $intentos = 0;

        while (true) {
            $intentos++;

            $respuesta = Http::withToken((string) $this->config['api_key'])
                ->timeout($this->config['timeout'])
                ->acceptJson()
                ->asJson()
                ->post($url, $cuerpo);

            if (in_array($respuesta->status(), $reintentarEn, true) && $intentos < $this->config['intentos']) {
                usleep(500_000 * $intentos);
                continue;
            }

            if ($respuesta->successful()) {
                return $respuesta;
            }

            throw new RuntimeException($this->mensajeDeError($respuesta, $url));
        }
    }

    private function mensajeDeError(Response $respuesta, string $url): string
    {
        $status = $respuesta->status();
        $detalle = $this->recortar((string) ($respuesta->json('message') ?? $respuesta->body()));
        $ruta = parse_url($url, PHP_URL_PATH) ?: $url;

        if (in_array($status, [404, 405], true) && in_array($ruta, self::RUTAS_DE_DECISION, true)) {
            $otra = $ruta === '/v1/systemone' ? '/api/v1/decisions' : '/v1/systemone';

            throw new JevSinRutaDeDecision(
                $ruta,
                "El proveedor no tiene la ruta {$ruta} (HTTP {$status}). Prueba JEV_PROTOCOLO=decisions "
                . "(o systemone), o JEV_PROTOCOLO=chat si el proveedor sólo habla OpenAI. La otra ruta de "
                . "decisiones de Jev es {$otra}."
            );
        }

        // DefAPI reconoce `typesafe/jev-1.13` en sus superficies de chat, pero no lo sirve: responde
        // «Model not supported» con 500. El mensaje crudo no dice qué hacer, así que se traduce.
        if ($status === 500 && str_contains($detalle, 'Model not supported')) {
            return "El proveedor conoce el modelo pero no lo sirve en esta superficie (HTTP 500: {$detalle}). "
                . 'En DefAPI pasa con /v1/chat/completions y /v1/messages: el modelo de Jev se usa por '
                . 'POST /api/v1/decisions (JEV_PROTOCOLO=decisions).';
        }

        return match ($status) {
            401 => 'Jev respondió 401: la clave es inválida o falta el header Authorization.',
            400, 422 => "Jev rechazó la petición ({$status}): {$detalle}. Revisa el modelo, el `state` y las preguntas.",
            404 => "El proveedor no tiene la ruta {$ruta} (HTTP 404): {$detalle}",
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

    /**
     * El costo que informa el proveedor, si lo informa (`usage.cost` en DefAPI, `consumed` en la raíz).
     *
     * @param  array<string,mixed>  $json
     */
    private function costo(array $json): ?float
    {
        $costo = $json['usage']['cost'] ?? $json['consumed'] ?? null;

        return is_numeric($costo) ? (float) $costo : null;
    }

    private function recortar(string $texto): string
    {
        $texto = preg_replace('/\s+/', ' ', trim($texto)) ?? '';

        return mb_strlen($texto) > 300 ? mb_substr($texto, 0, 300) . '…' : $texto;
    }
}
