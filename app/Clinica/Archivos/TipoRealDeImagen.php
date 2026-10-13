<?php

namespace App\Clinica\Archivos;

/**
 * **El tipo REAL de una imagen, por su contenido** (magic bytes), sin mirar el nombre ni confiar en `finfo` (WEB-T.1).
 *
 * `finfo`/`mime_content_type` depende de la versión de libmagic: según la instalación un HEIC sale como
 * `image/heic`, `image/heif` o `application/octet-stream`, y (trampa 31) usa la extensión del nombre como pista cuando
 * el contenido no alcanza. Para importar los archivos de la PC del consultorio hace falta una respuesta que **no
 * dependa del servidor**: la misma que da la herramienta `subir-imagenes` en la PC, así que la regla es esta cabecera y
 * nada más.
 *
 * Reconoce **JPEG, PNG, GIF, WEBP y HEIC/HEIF** (los HEIC de los celulares). No reconoce —y por lo tanto rechaza— BMP,
 * TIFF, AVIF, SVG, PDF ni ejecutables. Una imagen reconocida devuelve su MIME y la extensión con la que se guarda
 * (`ArchivosClinicos::nombreNuevo` pone el nombre; el del cliente no entra).
 *
 * HEIC/HEIF es una caja ISO (`....ftyp<marca>`): se mira la **marca principal**. `mif1`/`msf1` también la usa AVIF, que
 * se distingue porque lista `avif`/`avis` entre sus marcas compatibles.
 */
final class TipoRealDeImagen
{
    /** Cuántos bytes de cabecera alcanzan para decidir. */
    public const BYTES_DE_CABECERA = 64;

    /** Marcas principales de un HEIC (HEVC) y de un HEIF genérico. */
    private const MARCAS_HEIC = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'];

    private const MARCAS_HEIF = ['mif1', 'msf1', 'heif'];

    /**
     * @return array{mime:string, extension:string}|null  `null` si el contenido no es una imagen aceptada
     */
    public static function desdeCabecera(string $bytes): ?array
    {
        if (strlen($bytes) < 12) {
            return null;
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ['mime' => 'image/jpeg', 'extension' => 'jpg'];
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return ['mime' => 'image/png', 'extension' => 'png'];
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return ['mime' => 'image/gif', 'extension' => 'gif'];
        }

        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return ['mime' => 'image/webp', 'extension' => 'webp'];
        }

        if (substr($bytes, 4, 4) === 'ftyp') {
            return self::contenedorIso($bytes);
        }

        return null;
    }

    /**
     * @return array{mime:string, extension:string}|null  `null` si no se puede leer o no es una imagen aceptada
     */
    public static function desdeArchivo(string $rutaEnElSistema): ?array
    {
        if (! is_file($rutaEnElSistema) || ! is_readable($rutaEnElSistema)) {
            return null;
        }

        $f = @fopen($rutaEnElSistema, 'rb');
        if ($f === false) {
            return null;
        }

        $bytes = (string) fread($f, self::BYTES_DE_CABECERA);
        fclose($f);

        return self::desdeCabecera($bytes);
    }

    /** @return array{mime:string, extension:string}|null */
    private static function contenedorIso(string $bytes): ?array
    {
        $principal = substr($bytes, 8, 4);

        // Las marcas compatibles siguen a la versión menor (4 bytes) y llegan hasta el final de la caja `ftyp`.
        $largo = unpack('N', substr($bytes, 0, 4))[1] ?? 0;
        $fin = min(strlen($bytes), max(16, (int) $largo));
        $compatibles = [];
        for ($i = 16; $i + 4 <= $fin; $i += 4) {
            $compatibles[] = substr($bytes, $i, 4);
        }

        // AVIF no es un formato que se acepte: se distingue por su marca.
        if ($principal === 'avif' || $principal === 'avis' || in_array('avif', $compatibles, true) || in_array('avis', $compatibles, true)) {
            return null;
        }

        if (in_array($principal, self::MARCAS_HEIC, true)) {
            return ['mime' => 'image/heic', 'extension' => 'heic'];
        }

        if (in_array($principal, self::MARCAS_HEIF, true)) {
            return ['mime' => 'image/heif', 'extension' => 'heif'];
        }

        return null;
    }
}
