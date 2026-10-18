<?php

namespace App\Actions\Comentarios;

use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Models\Comentario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * **Borrar las imágenes adjuntas de un comentario** de "Ayúdanos a mejorar" (O-25): sus filas y sus archivos.
 *
 * Se usa al **descartar** el comentario. Descartar no borra el comentario —es el registro de lo que nos
 * dijeron—, pero las capturas son otra cosa: pueden traer datos de un paciente y no sirven para nada una vez
 * que el reporte no se va a atender, así que se van con él.
 *
 * ## El orden importa
 *
 * Primero las **filas** (en una transacción) y después los **archivos**. Si el borrado de las filas falla, la
 * transacción se deshace y los archivos **siguen** donde están: la base y el disco quedan como estaban. Al
 * revés —archivos primero— una fila viva apuntaría a un archivo que ya no está y la descarga daría 404 sin
 * explicación; el caso que queda, mucho menos grave, es un archivo sin fila si el disco falla justo después de
 * la transacción (nadie puede alcanzarlo, porque la descarga resuelve por id de fila).
 */
final class BorrarImagenesDelComentario
{
    /** @return int cuántas filas se borraron */
    public function ejecutar(Comentario $comentario): int
    {
        $filas = $comentario->imagenes()->get(['id', 'disco', 'ruta']);

        if ($filas->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($comentario): void {
            $comentario->imagenes()->delete();
        });

        foreach ($filas as $fila) {
            $disco = trim((string) $fila->disco) ?: ArchivosDeLosComentarios::DISCO;

            if (trim((string) $fila->ruta) !== '') {
                Storage::disk($disco)->delete($fila->ruta);
            }
        }

        return $filas->count();
    }
}
