<?php

namespace App\Clinica\Archivos;

use App\Actions\Configuracion\ReglasDelFormato;
use App\Models\Evolucion;
use App\Models\RecipeFormato;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * **El logo, la firma y el sello del médico** (WEB-8.2): en qué disco y carpeta viven, qué se acepta y cuándo se borra
 * el archivo anterior. Es la política que comparten el API del móvil y la web clínica.
 *
 * ## Dónde viven
 *
 * En el disco **`public`** de Storage, con las mismas rutas que usa el API desde el Paso 17/18: `logos/…`,
 * `recipe/firmas/…` y `recipe/sellos/…`, con el nombre aleatorio que arma `UploadedFile::store()`. No es una elección
 * nueva: el teléfono los baja por URL (`logo_url`, `firma_url`, `sello_url`) y la hoja de impresión de la web los lee
 * del mismo lugar. **Riesgo conocido, anotado y sin resolver acá:** cualquiera que tenga la URL ve la imagen (el nombre
 * es aleatorio, no secreto). Pasarlas a un disco privado con ruta autorizada cambiaría el contrato con el móvil.
 *
 * ## Qué se acepta
 *
 * El API acepta lo que acepta la regla `image` de Laravel hasta 2 MB. La web, además, exige el tipo **real** (el que
 * detecta el contenido): JPG, PNG o WEBP, sin SVG (un SVG puede llevar scripts y estas URLs son públicas).
 *
 * ## El archivo anterior
 *
 * Al reemplazar o quitar una imagen se borra la anterior **si ningún otro registro la usa**. Se comprueba contra las
 * tres columnas que guardan rutas (`evolucion.logo`, `recipe_formatos.firma` y `.sello`).
 */
final class ImagenesDelMembrete
{
    public const DISCO = 'public';

    public const CARPETA_LOGO = 'logos';

    public const CARPETA_FIRMA = 'recipe/firmas';

    public const CARPETA_SELLO = 'recipe/sellos';

    /** @var array<int,string> */
    public const EXTENSIONES = ['jpg', 'jpeg', 'png', 'webp'];

    /** @return array<int,string> */
    public static function mimes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * Las reglas de Laravel de una imagen subida desde la web: `file`, el tope del API, el tipo real y la extensión.
     *
     * Se compara `getMimeType()` con un cierre y no con la regla `mimetypes` (trampa 30: rechaza toda subida de un
     * formulario porque `getPath()` es vacío).
     *
     * @return array<int,mixed>
     */
    public static function reglas(): array
    {
        return [
            'file',
            'max:' . ReglasDelFormato::MAXIMO_IMAGEN_KB,
            function (string $atributo, $archivo, callable $fallar) {
                if (! $archivo instanceof UploadedFile) {
                    return;
                }

                if (! in_array($archivo->getMimeType(), self::mimes(), true)
                    || ! in_array(strtolower(pathinfo($archivo->getClientOriginalName(), PATHINFO_EXTENSION)), self::EXTENSIONES, true)
                    || ! self::esImagenReal($archivo)) {
                    $fallar('La imagen tiene que ser ' . self::textoAceptado() . '.');
                }
            },
        ];
    }

    /**
     * ¿El contenido es de verdad una imagen JPG, PNG o WEBP? Se lee el encabezado con `getimagesize()`: el MIME y la
     * extensión se pueden falsear (trampa 31), el encabezado decodificable no.
     */
    public static function esImagenReal(UploadedFile $archivo): bool
    {
        $info = @getimagesize($archivo->getPathname());

        return $info !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }

    /** "JPG, PNG o WEBP de hasta 2 MB": lo que se acepta, para la ayuda y los errores. */
    public static function textoAceptado(): string
    {
        return 'JPG, PNG o WEBP de hasta ' . round(ReglasDelFormato::MAXIMO_IMAGEN_KB / 1024) . ' MB';
    }

    /**
     * Guarda el archivo en el disco público con un nombre aleatorio y devuelve su ruta relativa al disco.
     *
     * @throws RuntimeException  si no se pudo escribir
     */
    public static function guardar(UploadedFile $archivo, string $carpeta): string
    {
        $ruta = $archivo->store($carpeta, self::DISCO);

        if (! is_string($ruta) || $ruta === '') {
            throw new RuntimeException('No se pudo guardar la imagen en el disco.');
        }

        return $ruta;
    }

    /** Borra el archivo si existe y **ningún** registro lo usa; llamar después de guardar la fila que ya no lo apunta. */
    public static function borrarSiNadieLoUsa(?string $ruta): void
    {
        $ruta = trim((string) $ruta);

        if ($ruta === '' || self::enUso($ruta)) {
            return;
        }

        Storage::disk(self::DISCO)->delete($ruta);
    }

    /** Borra el archivo sin comprobar nada: es el que se acaba de subir y la fila no quedó. */
    public static function descartar(?string $ruta): void
    {
        if ($ruta !== null && $ruta !== '') {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }

    /** ¿Alguna fila apunta a esta ruta? */
    public static function enUso(string $ruta): bool
    {
        return Evolucion::where('logo', $ruta)->exists()
            || RecipeFormato::where('firma', $ruta)->exists()
            || RecipeFormato::where('sello', $ruta)->exists();
    }
}
