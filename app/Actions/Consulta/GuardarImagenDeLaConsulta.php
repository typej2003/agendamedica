<?php

namespace App\Actions\Consulta;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\Consulta;
use App\Models\ImagenConsulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * **Guardar una imagen clínica de una consulta** (`imagen_consulta`, WEB-4.7, ítem 7 del panel: *Exámenes*).
 *
 * La fila **cuelga de la consulta** —`nrohistoria` + `nroconsulta`, las dos `NOT NULL` en la migración
 * núcleo (`2026_08_25_055351_create_medical_tables_schema.php:1213`)— y así la escribe el escritorio
 * cuando carga una imagen (`w_consulta_principal.srw:10744`). El mapa del dato, con la cita de la fuente
 * y las dos formas de fila que conviven en la tabla, está en `App\Clinica\Consulta\ImagenesDeLaConsulta`.
 *
 * ## Qué hace distinto de las otras Actions de la consulta
 *
 * Es la **única** que mueve un archivo, así que tiene tres responsabilidades que las demás no:
 *
 *  1. **Guardar en un disco privado** (`ArchivosClinicos::DISCO` → `storage/app/clinica`, fuera de
 *     `public/` y sin enlace simbólico). Nada de esto se sirve desde el servidor web: el único camino al
 *     archivo es la ruta autorizada del API, que compara el `reg_medico` del contexto con el dueño de la
 *     fila.
 *  2. **No confiar en el nombre del cliente**: lo arma el servidor (`ArchivosClinicos::ruta()`), con la
 *     extensión tomada del **contenido** y 40 caracteres al azar. El nombre que mandó el navegador sólo
 *     se usa para nada.
 *  3. **No dejar basura si la base falla**: el archivo se escribe primero y, si el `INSERT` no queda, se
 *     borra. En una subida de un archivo clínico, un huérfano es un dato de salud sin dueño.
 *
 * ## El rastro para el sync
 *
 * Deja **una** fila en `sync_changes` (`operation = created`, `source = web`) con el id de la fila nueva
 * y la ruta del archivo. `imagen_consulta` sólo la sube el escritorio —la lista blanca de
 * `config/sync_legado.php` línea 40, y su camino genérico de cambios—; el móvil no la sincroniza (no está
 * en las tablas de `SyncAppDataRequest`). El rastro es para que la fila que creó la web no llegue después
 * como una novedad y se duplique, igual que el `created` que el propio sync del escritorio registra
 * (`CambiosEscritorio`).
 */
final class GuardarImagenDeLaConsulta
{
    /** El largo de `imagen_consulta.observacion`: es un `text`, pero la descripción es una línea. */
    public const LARGO_DESCRIPCION = 200;

    /**
     * @param  CarbonInterface|null  $cuando  el momento de la subida; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién sube, para el rastro de `sync_changes`
     *
     * @throws RuntimeException  si el archivo no se pudo guardar
     */
    public function ejecutar(
        Consulta $consulta,
        UploadedFile $archivo,
        ?string $descripcion,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): ImagenConsulta {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $disco = ArchivosClinicos::DISCO;

        // El nombre lo arma el servidor; el del cliente no entra ni acá ni al disco.
        $ruta = ArchivosClinicos::ruta((string) $consulta->reg_medico, $archivo, $cuando->format('Y-m'));

        if (! Storage::disk($disco)->putFileAs(dirname($ruta), $archivo, basename($ruta), ['visibility' => 'private'])) {
            throw new RuntimeException('No se pudo guardar la imagen en el disco de archivos clínicos.');
        }

        try {
            $imagen = ImagenConsulta::create([
                'reg_medico'  => $consulta->reg_medico,
                // Las dos columnas son enteros en el esquema (a diferencia del pivote, que guarda el
                // número de historia como texto): la consulta ya las tiene como enteros.
                'nrohistoria' => (int) $consulta->numhistoria,
                'nroconsulta' => (int) $consulta->nroconsulta,
                'observacion' => $this->descripcion($descripcion),
                // `imagen` es `NOT NULL` en el esquema del legado y guarda **la ruta**, que es lo que la
                // columna quiere decir: en una fila del escritorio es la ruta absoluta de su PC
                // (`SetItem(llRow, "imagen", ls_Path)`, `:10744`) y en una de la web es la ruta relativa al
                // disco privado —la misma que el escritorio vería en su grilla, con el archivo ya en el
                // servidor—. `disco` es la marca de origen.
                'imagen'      => $ruta,
                'disco'       => $disco,
                'orden'       => $this->proximoOrden($consulta),
            ]);
        } catch (Throwable $e) {
            // El archivo ya está en el disco y la fila no: se borra antes de propagar. Un archivo clínico
            // sin fila que lo referencie no lo puede ver nadie —la descarga resuelve por id de fila— y
            // quedaría ocupando lugar para siempre.
            Storage::disk($disco)->delete($ruta);

            throw $e;
        }

        // El rastro del sync, con el id de la fila nueva: es la forma en que "esto ya está en la nube" se
        // distingue de una fila que llega por la carga inicial o por los cambios del escritorio.
        RegistroDeCambios::registrarOperacion(
            'imagen_consulta',
            (int) $imagen->id,
            $consulta->reg_medico,
            'created',
            'imagen',
            $ruta,
            $cuando,
            $origen,
        );

        return $imagen;
    }

    /** La descripción: el `""` del formulario y el `null` de la columna son el mismo estado (sin texto). */
    private function descripcion(?string $descripcion): ?string
    {
        $texto = $descripcion === null ? null : trim($descripcion);

        return $texto === '' ? null : $texto;
    }

    /**
     * La posición de la fila nueva en la lista de la consulta (`max + 1`).
     *
     * Se calcula acá —y no se le pasa desde la pantalla— porque es una regla del dato: la lista se ordena
     * por `orden` y dos subidas simultáneas no pueden quedar con el mismo número sin desordenar la grilla.
     */
    private function proximoOrden(Consulta $consulta): int
    {
        return (int) ImagenConsulta::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->max('orden') + 1;
    }
}
