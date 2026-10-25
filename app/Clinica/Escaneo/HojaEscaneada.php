<?php

namespace App\Clinica\Escaneo;

use App\Clinica\Archivos\TipoRealDeImagen;

/**
 * **La política del archivo que se escanea** (WEB-13.15): qué se acepta y hasta qué tamaño, sin HTTP.
 *
 * El tipo se decide por **la cabecera del contenido** (`TipoRealDeImagen` más la firma `%PDF-`), no por la
 * extensión ni por lo que diga el navegador: es lo que se le declara al proveedor, y un tipo mal declarado es un
 * 400 de su lado. Se aceptan los cuatro formatos de imagen que el proveedor lee (JPG, PNG, WEBP, GIF) y el PDF que
 * mandan muchos laboratorios. HEIC no: el proveedor no lo lee (el selector del navegador del iPhone ya entrega JPG).
 *
 * El archivo **no se guarda**: se lee, se manda y se descarta. Si el médico quiere conservar la hoja, la sube en
 * las imágenes de la consulta.
 */
final class HojaEscaneada
{
    /** El tope del archivo: 10 MB, el mismo de las imágenes de la consulta (y holgado para el límite del proveedor). */
    public const MAXIMO_KB = 10240;

    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    /** Lo que acepta el `<input type=file>` (una pista para el selector; la regla es `tipo()`). */
    public const ACEPTA = 'image/jpeg,image/png,image/webp,image/gif,application/pdf';

    /**
     * El lado mayor con el que viaja una foto. Una foto de teléfono pasa de 4000 px y de los ~5 MB por imagen que acepta
     * el proveedor (que de todos modos la reduce): se achica acá, con margen para que la letra chica siga legible.
     */
    public const LADO_MAXIMO = 2400;

    /** Una foto de más de esto viaja reducida aunque su lado no pase de `LADO_MAXIMO`. */
    public const BYTES_SIN_REDUCIR = 3_500_000;

        /** El tipo real del contenido, o `null` si no es uno de los aceptados. */
    public static function tipo(string $contenido): ?string
    {
        if (str_starts_with($contenido, '%PDF-')) {
            return 'application/pdf';
        }

        $mime = TipoRealDeImagen::desdeCabecera(substr($contenido, 0, TipoRealDeImagen::BYTES_DE_CABECERA))['mime'] ?? null;

        return in_array($mime, self::MIMES, true) ? $mime : null;
    }

    /**
     * El archivo como se manda a leer: el PDF y la foto chica, tal cual; la foto grande, reducida a `LADO_MAXIMO`,
     * **enderezada** según su EXIF (GD lo ignora y la hoja quedaría acostada) y recodificada como JPEG. Si GD no puede,
     * va la original.
     *
     * @return array{0:string, 1:string}  `[contenido, mime]`
     */
    public static function paraLeer(string $contenido, string $mime): array
    {
        if ($mime === 'application/pdf' || ! function_exists('imagecreatefromstring')) {
            return [$contenido, $mime];
        }

        $medidas = @getimagesizefromstring($contenido);
        if ($medidas === false) {
            return [$contenido, $mime];
        }

        $orientacion = self::orientacion($contenido, $mime);
        if (max($medidas[0], $medidas[1]) <= self::LADO_MAXIMO && strlen($contenido) <= self::BYTES_SIN_REDUCIR && $orientacion <= 1) {
            return [$contenido, $mime];
        }

        $origen = @imagecreatefromstring($contenido);
        if ($origen === false) {
            return [$contenido, $mime];
        }

        $origen = match ($orientacion) {
            3 => imagerotate($origen, 180, 0),
            6 => imagerotate($origen, -90, 0),
            8 => imagerotate($origen, 90, 0),
            default => $origen,
        };

        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        $escala = min(1, self::LADO_MAXIMO / max($ancho, $alto));
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        // Fondo blanco: un PNG con transparencia no queda negro.
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        ob_start();
        imagejpeg($destino, null, 88);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? [$contenido, $mime] : [$jpeg, 'image/jpeg'];
    }

    /** La orientación EXIF de un JPEG (1 = derecha), o 1 si no hay o no se puede leer. */
    private static function orientacion(string $contenido, string $mime): int
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contenido));

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }
}
