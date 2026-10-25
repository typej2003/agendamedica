<?php

namespace App\Clinica\Escaneo;

use Illuminate\Support\Facades\Log;

/**
 * **Qué se le pide al modelo y cómo se lee lo que contesta** (WEB-13.15), igual para todos los proveedores: las
 * instrucciones de transcripción, el esquema de la respuesta (`es_resultado` + `texto`) y su interpretación.
 *
 * `es_resultado` dice si el archivo es una hoja de resultados legible; si no lo es, se contesta 422 en vez de pegar
 * en la consulta lo que el modelo haya visto (una receta, una foto cualquiera). El texto se normaliza (saltos de
 * Unix, sin espacios al final ni renglones en blanco de más) antes de volver al campo.
 */
final class Transcripcion
{
    public const INSTRUCCIONES = <<<'TXT'
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

    /** El esquema de la respuesta, en el subconjunto de JSON Schema que aceptan los dos proveedores. */
    public const ESQUEMA = [
        'type' => 'object',
        'properties' => [
            'es_resultado' => ['type' => 'boolean'],
            'texto' => ['type' => 'string'],
        ],
        'required' => ['es_resultado', 'texto'],
        'additionalProperties' => false,
    ];

    /** Lo que se escribe junto al archivo en el mensaje del usuario. */
    public const PEDIDO = 'Transcribe esta hoja.';

    /**
     * El texto transcrito a partir del JSON que devolvió el modelo.
     *
     * @throws LecturaFallida  `proveedor` si no es el JSON pedido; `no_es_resultado` si el modelo dice que no lo es
     *                         o no transcribió nada
     */
    public static function deLaRespuesta(string $json): string
    {
        $lectura = json_decode($json, true);
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

    /** Saltos de línea de Unix, sin espacios al final de cada renglón ni renglones en blanco de más. */
    public static function limpiar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+$/m', '', $texto);
        $texto = preg_replace("/\n{3,}/", "\n\n", (string) $texto);

        return trim((string) $texto);
    }
}
