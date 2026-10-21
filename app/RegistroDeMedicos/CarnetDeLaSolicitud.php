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

    public static function textoMaximo(): string
    {
        return ArchivosClinicos::textoMaximo(self::PERFIL);
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
