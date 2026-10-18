<?php

namespace App\Clinica\Ayuda;

use App\Clinica\Archivos\ArchivosClinicos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * **La política de los archivos de "Ayúdanos a mejorar"** (O-25): en qué disco viven, con qué nombre se guardan
 * y qué se acepta.
 *
 * Reusa la política de los archivos clínicos —`App\Clinica\Archivos\ArchivosClinicos` con su **perfil de
 * comentario**: tipo **real** (el del contenido, no el que declara el cliente), extensión en la lista blanca y
 * tope—, que es el único lugar donde se valida un archivo que sube un usuario (PLAN-WEB.md, R3). Lo que agrega
 * es **dónde** vive: disco propio y una carpeta por cuenta.
 *
 * ## Por qué los archivos no van con los clínicos ni a `public/`
 *
 * Una captura de pantalla puede traer datos de un paciente, así que va a un **disco privado** con el mismo
 * criterio que las imágenes clínicas: `config/filesystems.php` → `comentarios` apunta a
 * `storage/app/comentarios`, **fuera** de `public/` y sin enlace simbólico. No hay ninguna URL del servidor web
 * que lo alcance: el único camino es la ruta autorizada del controlador, que comprueba quién pide (el autor del
 * comentario o quien atiende la bandeja).
 *
 * Se usa un disco **aparte** del de las imágenes clínicas (`clinica`) y no una carpeta adentro: estos archivos
 * no son parte de la historia clínica, se borran cuando el reporte se descarta y su retención es otra. Tenerlos
 * en su raíz hace que una copia de seguridad, una limpieza o una auditoría los distinga sin leer la base.
 *
 * ## Qué se acepta
 *
 * Hasta **3 imágenes** por comentario (lo valida el controlador, que es donde se conoce la lista), **5 MB** cada
 * una, **PNG/JPG/WEBP**. El nombre lo pone el servidor (`nombreNuevo()`): el que manda el navegador —el de la
 * captura pegada suele ser `image.png`, o ninguno— nunca toca el disco.
 */
final class ArchivosDeLosComentarios
{
    /** El disco privado de los adjuntos de un comentario (`config/filesystems.php` → `disks.comentarios`). */
    public const DISCO = 'comentarios';

    /** La carpeta raíz dentro del disco: `comentarios/<cuenta>/<año-mes>/<archivo>`. */
    public const CARPETA = 'comentarios';

    /** El perfil de la política de archivos que se aplica (5 MB, PNG/JPG/WEBP, sin GIF). */
    public const PERFIL = ArchivosClinicos::PERFIL_COMENTARIO;

    /**
     * Las reglas de validación del archivo: la política del perfil de comentario, sin el `required` (un adjunto
     * nunca es obligatorio; el comentario puede ir sólo con texto).
     *
     * @return array<int,mixed>
     */
    public static function reglas(): array
    {
        return ArchivosClinicos::reglas(self::PERFIL);
    }

    /** "PNG, JPG o WEBP": lo que se le muestra al usuario. */
    public static function textosAceptados(): string
    {
        return ArchivosClinicos::textosAceptados(self::PERFIL);
    }

    /** "5 MB": el tope que se le muestra al usuario. */
    public static function textoMaximo(): string
    {
        return ArchivosClinicos::textoMaximo(self::PERFIL);
    }

    /**
     * La carpeta **de esta cuenta** dentro del disco: `comentarios/<user_id>/<año-mes>`.
     *
     * El id va en la ruta aunque la autorización no dependa de ella (depende de la fila del comentario): hace
     * que una limpieza por cuenta o una auditoría se puedan hacer sin leer la base.
     */
    public static function carpeta(int $cuenta, ?string $mes = null): string
    {
        return self::CARPETA . '/' . $cuenta . '/' . ($mes ?? now()->format('Y-m'));
    }

    /**
     * La ruta que se guarda en la base: carpeta + nombre del servidor, siempre relativa al disco y con `/`.
     *
     * Se comprueba que quede **dentro** de la carpeta de la cuenta, como en las imágenes clínicas: es la última
     * barrera contra un nombre que se escape.
     */
    public static function ruta(int $cuenta, UploadedFile $archivo, ?string $mes = null): string
    {
        $carpeta = self::carpeta($cuenta, $mes);
        $extension = ArchivosClinicos::extension($archivo, self::PERFIL);

        if ($extension === null) {
            throw new InvalidArgumentException('El archivo no es una imagen de un tipo aceptado.');
        }

        $ruta = $carpeta . '/' . Str::random(40) . '.' . $extension;

        if (! ArchivosClinicos::dentroDe($ruta, $carpeta)) {
            throw new InvalidArgumentException('La ruta del archivo se sale de la carpeta de la cuenta.');
        }

        return $ruta;
    }
}
