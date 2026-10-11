<?php

namespace App\Actions\Consulta;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\ImagenConsulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * **Quitar una imagen clínica de una consulta** (`imagen_consulta`, WEB-4.7).
 *
 * Es la otra mitad de la subida: el médico carga una foto equivocada —o sube dos veces la misma— y tiene
 * que poder sacarla. El escritorio lo hace con el botón de borrar de su grilla, que ejecuta
 * `DELETE FROM imagen_consulta ...` por la fila elegida; acá se borra **la fila y su archivo**, que es
 * lo que el escritorio no puede hacer (su `imagen` es una ruta del disco de la PC: borrar la fila no
 * borra el archivo del consultorio, y eso **no** se toca: los archivos viejos son de WEB-T.1).
 *
 * ## Qué borra y qué no
 *
 *  - Borra la fila de `imagen_consulta` y, **sólo si el archivo es del disco privado** (`archivo` con una
 *    ruta relativa), el archivo del disco.
 *  - Una fila del **escritorio** (su `C:\…` en `imagen`) no tiene archivo en el servidor: se borra la fila
 *    y **no** se toca nada más. El archivo sigue en la PC del consultorio, que es donde está.
 *  - Deja su rastro en `sync_changes` (`operation = deleted`, `source = web`), que es lo que impide que una
 *    copia posterior del escritorio vuelva a crear la fila como si fuera nueva.
 *
 * La autorización **no** se resuelve acá: quien llama ya comprobó que la imagen es de este médico y de
 * esta consulta (`ImagenesDeLaConsulta::delContexto()`); la Action recibe la fila.
 */
final class QuitarImagenDeLaConsulta
{
    /**
     * @param  CarbonInterface|null  $cuando  el momento de la baja; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién borra, para el rastro de `sync_changes`
     */
    public function ejecutar(
        ImagenConsulta $imagen,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): void {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();

        $archivo = trim((string) $imagen->imagen);
        $disco = trim((string) $imagen->disco) ?: ArchivosClinicos::DISCO;
        $id = (int) $imagen->id;
        $regMedico = $imagen->reg_medico;

        $imagen->delete();

        // El archivo, después de la fila: si la fila no se pudo borrar, el archivo tiene que seguir (es lo
        // que la fila referencia). Al revés —archivo primero— una fila viva apuntaría a un archivo que ya
        // no está, y la descarga daría 404 sin explicación.
        //
        // Y sólo si el archivo es de la web (`disco` con el disco de Laravel): una fila del escritorio
        // tiene una ruta del disco de su PC, que **no** se toca (esos archivos son de WEB-T.1).
        if ($imagen->disco !== null && $archivo !== '' && ArchivosClinicos::esRutaDelDisco($archivo)) {
            Storage::disk($disco)->delete($archivo);
        }

        RegistroDeCambios::registrarOperacion(
            'imagen_consulta',
            $id,
            $regMedico,
            'deleted',
            'imagen',
            $archivo === '' ? null : $archivo,
            $cuando,
            $origen,
        );
    }
}
