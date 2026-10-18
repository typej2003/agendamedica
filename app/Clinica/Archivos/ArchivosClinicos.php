<?php

namespace App\Clinica\Archivos;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * **La política de los archivos clínicos que sube un usuario** (WEB-4.7): en qué disco viven, con qué
 * nombre se guardan y qué se acepta.
 *
 * Es una clase **sin estado y de una sola fuente**: las reglas de la subida no pueden vivir dos veces
 * (PLAN-WEB.md, R3: "ninguna regla de negocio se escribe dos veces"). Hoy la usa la web clínica
 * (`App\Actions\Consulta\GuardarImagenDeLaConsulta` → `examenes.imagenes` de la consulta) y es el lugar
 * donde tiene que vivir cualquier otra superficie que suba un archivo clínico (el móvil, cuando suba
 * imágenes, y la copia de las imágenes viejas del escritorio de WEB-T.1).
 *
 * ## Por qué el disco es privado y no `public`
 *
 * Las imágenes clínicas son datos de salud de un paciente: **no** se sirven desde el servidor web. El
 * disco `clinica` apunta a `storage/app/clinica`, que está **fuera** de `public/` (igual que el disco
 * `local`, que ya usa `storage/app` para la restauración del escritorio) y **no** tiene enlace simbólico
 * en `public/`: no hay ninguna URL que lo alcance. El único camino al archivo es la ruta autorizada del
 * API, que resuelve el `reg_medico` del contexto contra el dueño del archivo (ver
 * `ConsultaImagenesController::descargar`).
 *
 * Esto es una decisión, no un detalle: el logo, la firma y el sello del médico sí viven en el disco
 * `public` (los imprime el récipe que ve el paciente), pero una imagen de una colposcopia o de una
 * ecografía no. Si algún día se quiere servir por CDN, se hace con URLs firmadas de vida corta, no
 * mudando el archivo a `public/`.
 *
 * ## Qué se acepta
 *
 * Tipo **real** (el que detecta el contenido, no el que declara el cliente), extensión dentro de la
 * lista blanca, y tamaño máximo. El nombre lo pone el servidor (ver `nombreNuevo()`): el nombre que manda
 * el navegador **nunca** toca el disco, así que un `../` o un nombre que pise el archivo de otro médico
 * no tienen por dónde entrar.
 */
final class ArchivosClinicos
{
    /**
     * El disco privado de los archivos clínicos (`config/filesystems.php` → `disks.clinica`).
     *
     * El nombre se guarda en la fila (`imagen_consulta.disco`), así que un día se puede mudar a otro
     * disco —una nube, un servidor de archivos— sin tocar las filas viejas.
     */
    public const DISCO = 'clinica';

    /**
     * La carpeta raíz dentro del disco: la primera parte de la ruta que se guarda en la base
     * (`examenes/<reg_medico>/<año-mes>/<archivo>`).
     */
    public const CARPETA = 'examenes';

    /** El tope de la subida, en KB (Laravel valida `max:` en KB). Son 10 MB. */
    public const MAXIMO_KB = 10240;

    /**
     * Las extensiones aceptadas, en minúscula. La imagen clínica llega de un equipo médico, del celular
     * del médico o de un archivo que le pasaron: son los cuatro formatos que el navegador muestra sin
     * complementos (`jpg`, `jpeg`, `png`, `gif`, `webp`).
     */
    public const EXTENSIONES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * **Los dos perfiles de la política.** El de la imagen clínica es el de siempre y el que se usa si no se
     * dice nada; el de la captura de un comentario (O-25) es más chico y más estricto porque un reporte no
     * necesita un GIF ni 10 MB.
     *
     * La política sigue viviendo en **un solo lugar** (PLAN-WEB.md, R3): lo que cambia entre perfiles son tres
     * valores —tipos, extensiones y tope—, no las reglas. Así una superficie nueva elige un perfil en vez de
     * escribir su propia validación de archivos.
     */
    public const PERFIL_CLINICO = 'clinico';
    public const PERFIL_COMENTARIO = 'comentario';

    /** El tope de una captura de "Ayúdanos a mejorar", en KB: son 5 MB. */
    public const MAXIMO_KB_COMENTARIO = 5120;

    /**
     * Los formatos de una captura: PNG, JPG y WEBP, que es lo que pega un navegador y muestra sin
     * complementos. Sin GIF: una captura de pantalla nunca es una animación.
     */
    public const EXTENSIONES_COMENTARIO = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Los MIME **reales** aceptados. El orden es el de la lista de extensiones para que se lea junto.
     *
     * Se comprueba contra el MIME detectado por PHP (`$file->getMimeType()`, que mira el contenido), no
     * contra `getClientMimeType()`: el cliente puede mentir, y además es lo que Laravel compara en la
     * regla `mimes`.
     *
     * @return array<int,string>
     */
    public static function mimes(?string $perfil = null): array
    {
        if ($perfil === self::PERFIL_COMENTARIO) {
            return ['image/jpeg', 'image/png', 'image/webp'];
        }

        return ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    }

    /**
     * Las extensiones del perfil, en minúscula (la lista blanca que se le exige al nombre que manda el
     * cliente).
     *
     * @return array<int,string>
     */
    public static function extensiones(?string $perfil = null): array
    {
        return $perfil === self::PERFIL_COMENTARIO ? self::EXTENSIONES_COMENTARIO : self::EXTENSIONES;
    }

    /** El tope del perfil, en KB (Laravel valida `max:` en KB). */
    public static function maximoKb(?string $perfil = null): int
    {
        return $perfil === self::PERFIL_COMENTARIO ? self::MAXIMO_KB_COMENTARIO : self::MAXIMO_KB;
    }

    /**
     * Las reglas de validación de Laravel del archivo: **tipo real** y **tamaño**, con la lista blanca de
     * la política como única fuente.
     *
     * Se comprueba el MIME con un cierre y **no** con la regla `mimetypes` de Laravel, que es la que
     * parece natural y no sirve acá: `validateMimetypes()` exige `$value->getPath() !== ''` y en las
     * subidas de un `<form>` (y en los `UploadedFile::fake()` de los tests) el `getPath()` del archivo es
     * **vacío**, así que la regla rechaza **todo** con el mensaje de "no es un tipo permitido" —incluidos
     * los PNG reales—. Con el cierre se compara `getMimeType()`, que es el tipo detectado del **contenido**
     * (`finfo`), y el mensaje es el de la política.
     *
     * La **extensión** se comprueba aparte, en `extensionPermitida()`: `mime_content_type` resuelve el
     * tipo por el contenido y el nombre que mandó el cliente no se usa para el archivo, pero una foto
     * `.exe` es una señal que se rechaza igual (defensa en profundidad, y es lo que la tarea pide).
     *
     * @return array<int,mixed>
     */
    public static function reglas(?string $perfil = null): array
    {
        return [
            'file',
            'max:' . self::maximoKb($perfil),
            function (string $atributo, $archivo, callable $fallar) use ($perfil) {
                if (! $archivo instanceof UploadedFile) {
                    return;
                }

                if (! in_array($archivo->getMimeType(), self::mimes($perfil), true)) {
                    $fallar('El archivo no es una imagen de un tipo aceptado (' . self::textosAceptados($perfil) . ').');
                }

                if (! self::extensionPermitida($archivo->getClientOriginalName(), $perfil)) {
                    $fallar('La imagen tiene que ser ' . self::textosAceptados($perfil) . '.');
                }
            },
        ];
    }

    /**
     * **Las reglas completas de una subida**, ya con el `required`: es lo que el controlador pone en su
     * `validate()` (`'imagen' => ArchivosClinicos::reglasDeSubida()`).
     *
     * Existe para que la parte de texto y el cierre que comprueba el tipo **no se puedan separar**: el
     * cierre es el que mira el MIME y quien use sólo `'required|file|max:…'` estaría validando el tamaño y
     * nada más. Fue el error que se midió al escribir esta tarea (un `text/plain` entraba como si fuera una
     * imagen), y por eso no hay una versión "sólo texto" de estas reglas.
     *
     * @return array<int,mixed>
     */
    public static function reglasDeSubida(?string $perfil = null): array
    {
        return array_merge(['required'], self::reglas($perfil));
    }

    /**
     * ¿La extensión del nombre que mandó el cliente está en la lista blanca? Compara sobre el nombre
     * **tal cual lo mandó el cliente**, que es justamente lo que se está auditando; la extensión con la
     * que el archivo se guarda sale del contenido (`extension()`).
     */
    public static function extensionPermitida(string $nombre, ?string $perfil = null): bool
    {
        $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        return in_array($extension, self::extensiones($perfil), true);
    }

    /** "JPG, PNG, GIF o WEBP": el texto humano de lo que se acepta (una vez cada formato). */
    public static function textosAceptados(?string $perfil = null): string
    {
        // `jpeg` y `jpg` son el mismo formato: se muestra una sola vez.
        $formatos = array_values(array_diff(array_unique(self::extensiones($perfil)), ['jpeg']));
        $formatos = array_map('strtoupper', $formatos);
        $ultimo = array_pop($formatos);

        return implode(', ', $formatos) . ' o ' . $ultimo;
    }

    /** "10 MB": el texto humano del tope. */
    public static function textoMaximo(?string $perfil = null): string
    {
        return round(self::maximoKb($perfil) / 1024) . ' MB';
    }

    /**
     * La extensión con la que se guarda el archivo, tomada **del contenido** (el MIME real) y no del
     * nombre que mandó el cliente: un `foto.jpg` que en realidad es un PNG se guarda `.png`.
     *
     * @return string|null  `null` si el contenido no es de un tipo aceptado
     */
    public static function extension(UploadedFile $archivo, ?string $perfil = null): ?string
    {
        $extension = match ($archivo->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => null,
        };

        // El perfil de comentario no acepta GIF: el contenido lo dice el MIME, pero la extensión con la que se
        // guardaría no está en su lista blanca, así que el archivo no entra.
        return $extension !== null && in_array($extension, self::extensiones($perfil), true) ? $extension : null;
    }

    /**
     * El nombre del archivo en el disco: **lo arma el servidor**, con la extensión del contenido y 40
     * caracteres al azar.
     *
     * Tres cosas que resuelve y ninguna es cosmética:
     *
     *  - **Nada del nombre del cliente toca el disco**: ni el nombre ni la extensión que mandó. Un
     *    `../../algo.php` no es un nombre que se use, es un nombre que se descarta.
     *  - **No se pisa un archivo de otro médico**: el azar hace que dos subidas distintas no colisionen,
     *    y aunque colisionaran, cada una vive bajo la carpeta de su `reg_medico`.
     *  - **No se puede adivinar**: la URL no es el nombre del archivo, es el id de su fila.
     */
    public static function nombreNuevo(UploadedFile $archivo, ?string $perfil = null): string
    {
        $extension = self::extension($archivo, $perfil);

        if ($extension === null) {
            throw new InvalidArgumentException('El archivo no es una imagen de un tipo aceptado.');
        }

        return Str::random(40) . '.' . $extension;
    }

    /**
     * La carpeta **de este médico** dentro del disco: `examenes/<reg_medico>/<año-mes>`.
     *
     * El `reg_medico` va en la ruta aunque la autorización no dependa de ella (depende de la fila): hace
     * que una copia de seguridad, un borrado o una auditoría se puedan hacer por médico sin leer la base.
     * Se sanea porque es un valor que viene del contexto y no tiene por qué tener una barra.
     */
    public static function carpeta(string $regMedico, ?string $mes = null): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9._-]/', '_', $regMedico) ?: 'sin-registro';

        return self::CARPETA . '/' . $limpio . '/' . ($mes ?? now()->format('Y-m'));
    }

    /**
     * La ruta que se guarda en la base: carpeta + nombre, siempre relativa al disco y con `/`.
     *
     * Se comprueba que quede **dentro** de la carpeta del médico: es la última barrera contra un nombre
     * que se escape (hoy `nombreNuevo()` no puede producirlo, pero esta es la función que se va a
     * reutilizar cuando otra superficie suba archivos, y una comprobación acá vale más que confiar en el
     * llamador).
     */
    public static function ruta(string $regMedico, UploadedFile $archivo, ?string $mes = null, ?string $perfil = null): string
    {
        $carpeta = self::carpeta($regMedico, $mes);
        $ruta = $carpeta . '/' . self::nombreNuevo($archivo, $perfil);

        if (! self::dentroDe($ruta, $carpeta)) {
            throw new InvalidArgumentException('La ruta del archivo se sale de la carpeta del médico.');
        }

        return $ruta;
    }

    /**
     * ¿`$ruta` está dentro de `$carpeta`? Se normalizan los `..` antes de comparar, así que
     * `examenes/x/../y` no cuenta como dentro de `examenes/x`.
     */
    public static function dentroDe(string $ruta, string $carpeta): bool
    {
        $normalizar = static function (string $camino): string {
            $partes = [];

            foreach (explode('/', str_replace('\\', '/', $camino)) as $parte) {
                if ($parte === '' || $parte === '.') {
                    continue;
                }
                if ($parte === '..') {
                    array_pop($partes);
                    continue;
                }
                $partes[] = $parte;
            }

            return implode('/', $partes);
        };

        $ruta = $normalizar($ruta);
        $carpeta = $normalizar($carpeta);

        return $ruta !== $carpeta && str_starts_with($ruta, $carpeta . '/');
    }

    /**
     * El nombre que se **muestra** del archivo. Del legado (`imagen_consulta.imagen`) viene una ruta
     * absoluta del disco de la PC del consultorio —`C:\Users\IBYTE\Downloads\20211025_121548.jpg`— y lo
     * único que el médico reconoce es el nombre del archivo; de lo que sube la web, el nombre aleatorio
     * no dice nada, así que se muestra el del archivo del disco. Nunca se muestra la ruta del sistema:
     * eso es información de la instalación, no del paciente.
     */
    public static function nombreVisible(?string $ruta): ?string
    {
        $ruta = trim((string) $ruta);

        if ($ruta === '') {
            return null;
        }

        $nombre = basename(str_replace('\\', '/', $ruta));

        return $nombre === '' ? null : $nombre;
    }

    // ------------------------------------------------------------------------- Importación (WEB-T.1)

    /**
     * Las extensiones de lo que **se importa** desde el disco de la PC del consultorio (WEB-T.1): las de la
     * subida de la web **más HEIC/HEIF** (las fotos de un celular). El formulario de la web **no** las acepta
     * (`EXTENSIONES` y `mimes()` no cambiaron): un HEIC no se muestra en el navegador y sólo se importa para que
     * el médico no pierda el archivo; se ofrece como descarga.
     */
    public const EXTENSIONES_IMPORTADAS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];

    /** El tope de un archivo importado, en bytes (el mismo de la subida de la web). */
    public static function maximoBytes(): int
    {
        return self::MAXIMO_KB * 1024;
    }

    /**
     * Los MIME reales que se importan: los de la web y los dos de HEIC/HEIF.
     *
     * @return array<int,string>
     */
    public static function mimesImportados(): array
    {
        return array_merge(self::mimes(), ['image/heic', 'image/heif']);
    }

    /**
     * El MIME que se manda al **servir** un archivo según la extensión con la que se guardó (la fija el
     * servidor, desde el contenido). `Storage::response()` lo deduce de `finfo`, que según la versión de
     * libmagic da `application/octet-stream` para un HEIC: se dice explícito.
     */
    public static function tipoPorExtension(?string $ruta): ?string
    {
        return match (strtolower(pathinfo((string) $ruta, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'heic'        => 'image/heic',
            'heif'        => 'image/heif',
            default       => null,
        };
    }

    /** ¿El navegador no muestra este archivo y hay que ofrecerlo como descarga (HEIC/HEIF)? */
    public static function esDeDescarga(?string $ruta): bool
    {
        return in_array(strtolower(pathinfo((string) $ruta, PATHINFO_EXTENSION)), ['heic', 'heif'], true);
    }

    /**
     * La carpeta de lo que se importó de la PC: `importadas/<reg_medico>/<tabla>/<año-mes>`. Separada de
     * `examenes/` (lo que sube la web) para que una auditoría o una copia de seguridad distinga el origen sin
     * leer la base.
     */
    public static function carpetaImportada(string $regMedico, string $tabla, ?string $mes = null): string
    {
        $medico = preg_replace('/[^A-Za-z0-9._-]/', '_', $regMedico) ?: 'sin-registro';
        $tablaLimpia = preg_replace('/[^A-Za-z0-9._-]/', '_', $tabla) ?: 'sin-tabla';

        return 'importadas/' . $medico . '/' . $tablaLimpia . '/' . ($mes ?? now()->format('Y-m'));
    }

    /**
     * La ruta (relativa al disco) de un archivo importado: carpeta + 40 caracteres al azar + **la extensión del
     * contenido** (nunca la del nombre de la PC). Se comprueba que quede dentro de la carpeta, como `ruta()`.
     */
    public static function rutaImportada(string $regMedico, string $tabla, string $extension, ?string $mes = null): string
    {
        if (! in_array($extension, self::EXTENSIONES_IMPORTADAS, true)) {
            throw new InvalidArgumentException('La extensión no está entre las que se importan.');
        }

        $carpeta = self::carpetaImportada($regMedico, $tabla, $mes);
        $ruta = $carpeta . '/' . Str::random(40) . '.' . $extension;

        if (! self::dentroDe($ruta, $carpeta)) {
            throw new InvalidArgumentException('La ruta del archivo se sale de la carpeta del médico.');
        }

        return $ruta;
    }

    /**
     * El nombre con el que se **muestra y se descarga** un archivo importado de la PC (WEB-T.1): el que tenía en el
     * consultorio, limpio (sin barras, comillas ni caracteres de control: va dentro de un `Content-Disposition`) y con la
     * extensión del contenido guardado si la del nombre original es otra (`escaneo.dat` que es un JPG se baja `escaneo.dat.jpg`).
     */
    public static function nombreDeDescarga(string $nombreOriginal, string $rutaGuardada): string
    {
        $limpio = preg_replace('~[[:cntrl:]"\\\\/:*?<>|;]+~u', '_', trim($nombreOriginal)) ?? '';
        $limpio = mb_substr(trim($limpio, " ._"), 0, 120);

        $extension = strtolower(pathinfo($rutaGuardada, PATHINFO_EXTENSION));
        if ($limpio === '') {
            return basename($rutaGuardada);
        }

        $propia = strtolower(pathinfo($limpio, PATHINFO_EXTENSION));
        $equivalentes = $extension === 'jpg' ? ['jpg', 'jpeg'] : ($extension === 'heic' ? ['heic', 'heif'] : [$extension]);

        return in_array($propia, $equivalentes, true) ? $limpio : $limpio . '.' . $extension;
    }

    /** ¿Esta ruta es una de las que guarda la web (relativa al disco) y no una del disco de la PC? */
    public static function esRutaDelDisco(?string $ruta): bool
    {
        $ruta = trim((string) $ruta);

        if ($ruta === '') {
            return false;
        }

        // Una ruta de Windows (`C:\…`) o de Unix (`/…`) es del escritorio: apunta al disco de la PC del
        // consultorio y no existe en el servidor (PLAN-WEB.md §3.9).
        return ! str_starts_with($ruta, '/')
            && ! str_starts_with($ruta, '\\')
            && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $ruta);
    }
}
