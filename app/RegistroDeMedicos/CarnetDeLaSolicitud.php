<?php

namespace App\RegistroDeMedicos;

use App\Clinica\Archivos\ArchivosClinicos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * **La foto del carnet** que el médico adjunta a su solicitud (opcional): dónde vive y qué se acepta.
 *
 * Reusa la política de los archivos que sube un usuario (`ArchivosClinicos`, perfil de comentario: PNG/JPG/WEBP,
 * 5 MB, tipo real del contenido). Va a un **disco privado propio** (`solicitudes`, `storage/app/solicitudes`):
 * trae la cédula y el número de colegiatura de una persona, así que ninguna URL pública lo alcanza; lo sirve
 * sólo la ruta de la bandeja de `/admin`. El nombre lo pone el servidor.
 */
final class CarnetDeLaSolicitud
{
    public const DISCO = 'solicitudes';

    public const PERFIL = ArchivosClinicos::PERFIL_COMENTARIO;

    /** @return array<int,mixed> */
    public static function reglas(): array
    {
        return ArchivosClinicos::reglas(self::PERFIL);
    }

    public static function textosAceptados(): string
    {
        return ArchivosClinicos::textosAceptados(self::PERFIL);
    }

    /**
     * El tope **efectivo** de la foto, en KB: el de la política (5 MB) o el que impone PHP, **el menor**.
     *
     * `upload_max_filesize` y `post_max_size` vienen de fábrica en 2M y 8M, y son **menores** que el tope de la
     * política: una foto de celular (3-8 MB) la descarta PHP **antes** de que el código la vea y el navegador
     * sólo recibe el «No se pudo subir el campo carnet» del framework. Con esto el formulario y sus mensajes
     * anuncian el tope real del servidor (2026-10-24; ver la trampa 86).
     */
    public static function maximoKb(): int
    {
        $politica = ArchivosClinicos::maximoKb(self::PERFIL);

        $delServidor = array_filter([
            self::limiteEnKb((string) ini_get('upload_max_filesize')),
            self::limiteEnKb((string) ini_get('post_max_size')),
        ], static fn (?int $kb): bool => $kb !== null);

        return $delServidor === [] ? $politica : min($politica, min($delServidor));
    }

    public static function textoMaximo(): string
    {
        return round(self::maximoKb() / 1024) . ' MB';
    }

    /** «2M», «512K», «1G» → KB. `null` si no hay tope (vacío, `0` o `-1`). */
    private static function limiteEnKb(string $valor): ?int
    {
        $valor = trim($valor);

        if ($valor === '' || (int) $valor <= 0) {
            return null;
        }

        $numero = (int) $valor;

        return match (strtoupper(substr($valor, -1))) {
            'G' => $numero * 1048576,
            'M' => $numero * 1024,
            'K' => $numero,
            default => (int) round($numero / 1024),
        };
    }

    /**
     * Guarda el archivo y devuelve lo que se anota en la solicitud.
     *
     * @return array{carnet_ruta: string, carnet_tipo: ?string, carnet_nombre: string}
     */
    public function guardar(UploadedFile $archivo): array
    {
        $extension = ArchivosClinicos::extension($archivo, self::PERFIL);

        if ($extension === null) {
            throw new InvalidArgumentException('El archivo no es una imagen de un tipo aceptado.');
        }

        $carpeta = 'carnets/' . now()->format('Y-m');
        $nombre = Str::random(40) . '.' . $extension;

        if (! Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombre, ['visibility' => 'private'])) {
            throw new RuntimeException('No se pudo guardar la foto del carnet.');
        }

        return [
            'carnet_ruta' => $carpeta . '/' . $nombre,
            'carnet_tipo' => $archivo->getMimeType(),
            'carnet_nombre' => mb_substr(basename((string) $archivo->getClientOriginalName()), 0, 200) ?: 'carnet.' . $extension,
        ];
    }

    /** Borra una foto que quedó sin solicitud (se reemplazó antes de confirmar el código). */
    public function borrar(?string $ruta): void
    {
        if ($ruta !== null && $ruta !== '') {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }
}
