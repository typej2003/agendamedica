<?php

namespace App\Clinica\Escaneo;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * **La lectura de una hoja de resultados con Claude** (WEB-13.15): manda la foto o el PDF y pide la
 * **transcripción literal** —no una interpretación— en texto plano, con un examen por renglón.
 *
 * Decisiones:
 *
 *  - **Salida estructurada** (`json_schema`): `es_resultado` dice si el archivo es una hoja de resultados legible;
 *    si no lo es, se contesta 422 en vez de pegar en la consulta lo que el modelo haya visto (una receta, una
 *    foto cualquiera).
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

    private const INSTRUCCIONES = <<<'TXT'
        Eres un transcriptor de hojas de resultados de laboratorio y de informes de estudios médicos. Recibes la foto
        o el PDF de una hoja y devuelves su contenido como texto plano, en español, para que el médico lo revise y lo
        pegue en la historia clínica.

        Reglas:
        - Transcribe; no interpretes, no resumas, no diagnostiques y no agregues comentarios propios.
        - Copia los valores, las unidades y los rangos de referencia exactamente como aparecen. Nunca corrijas,
          redondees ni completes un número.
        - Un examen por renglón, con la forma: NOMBRE DEL EXAMEN: valor unidad (ref.: rango). Omite «(ref.: …)» si la
          hoja no trae rango. Conserva las marcas de valor alterado (H, L, *, ↑, ↓) que traiga la hoja.
        - Si la hoja agrupa los exámenes (hematología, química, orina…), escribe el nombre del grupo en un renglón
          propio, en mayúsculas, y deja un renglón en blanco entre grupos.
        - Si es un informe en prosa (un estudio de imagen, una biopsia), transcribe el texto tal cual, por párrafos.
        - En la primera línea, si aparecen, el laboratorio y la fecha de la muestra o del informe: «Laboratorio: …
          — Fecha: …». No transcribas los datos de identidad del paciente (nombre, cédula, dirección, teléfono).
        - Lo que no se lea con seguridad se escribe [ilegible]; nunca lo adivines.
        - Sin Markdown: ni asteriscos, ni tablas, ni viñetas.
        - Si el archivo no es una hoja de resultados o de un informe médico, o no se puede leer, responde
          es_resultado = false y texto vacío.
        TXT;

    private const ESQUEMA = [
        'type' => 'object',
        'properties' => [
            'es_resultado' => ['type' => 'boolean'],
            'texto' => ['type' => 'string'],
        ],
        'required' => ['es_resultado', 'texto'],
        'additionalProperties' => false,
    ];

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
                        ['type' => 'text', 'text' => 'Transcribe esta hoja.'],
                    ],
                ]],
                model: $this->config['modelo_ocr'],
                fallbacks: 'default',
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::ESQUEMA]],
                system: self::INSTRUCCIONES,
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

        $lectura = json_decode($texto, true);
        if (! is_array($lectura) || ! array_key_exists('es_resultado', $lectura)) {
            Log::warning('Lectura de hoja: la respuesta no es el JSON pedido');

            throw LecturaFallida::delProveedor();
        }

        $transcripcion = self::limpiar((string) ($lectura['texto'] ?? ''));
        if ($lectura['es_resultado'] !== true || $transcripcion === '') {
            throw LecturaFallida::noEsResultado();
        }

        return $transcripcion;
    }

    /** El bloque de contenido del archivo: `document` para el PDF, `image` para la foto. */
    private function bloqueDelArchivo(string $contenido, string $mime): array
    {
        $fuente = ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($contenido)];

        return ['type' => $mime === 'application/pdf' ? 'document' : 'image', 'source' => $fuente];
    }

    /** Saltos de línea de Unix, sin espacios al final de cada renglón ni renglones en blanco de más. */
    private static function limpiar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+$/m', '', $texto);
        $texto = preg_replace("/\n{3,}/", "\n\n", (string) $texto);

        return trim((string) $texto);
    }

    private function cliente(): Client
    {
        return $this->cliente ??= new Client(apiKey: (string) $this->config['api_key']);
    }
}
