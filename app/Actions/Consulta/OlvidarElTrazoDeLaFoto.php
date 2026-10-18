<?php

namespace App\Actions\Consulta;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\DibujoDelExamen;
use App\Models\EleccionDeImagenDelExamen;
use Illuminate\Support\Facades\Storage;

/**
 * **El trazo se va con su foto** (O-09, regla 5): cuando una foto del estudio se quita —de la solapa o porque otra la
 * reemplaza—, su marca no puede quedar huérfana. Esta Action borra el trazo de esa foto (la fila y el PNG del disco) y
 * limpia la referencia de la elección, que si no apuntaría a una imagen que ya no existe.
 *
 * La pantalla **avisa antes de borrar** (`_imagenes-lista`): el trazo no se puede recuperar.
 *
 * No decide quién puede borrar: la foto ya pasó por la autorización de la consulta (`ImagenesDeLaConsulta::delContexto`)
 * y acá sólo llega su id. Sin base de datos más que las dos tablas nuevas y sin tocar `imagen_consulta`.
 */
final class OlvidarElTrazoDeLaFoto
{
    public function ejecutar(int $imagen): void
    {
        $disco = Storage::disk(ArchivosClinicos::DISCO);

        foreach (DibujoDelExamen::where('imagen_id', $imagen)->get() as $dibujo) {
            if ($dibujo->archivo !== null && $dibujo->archivo !== '') {
                $disco->delete($dibujo->archivo);
            }

            $dibujo->delete();
        }

        // La elección puede estar apuntando a la foto que se va: se limpia para que el estado no quede mintiendo (la
        // lectura cae a la primera foto, y si no hay ninguna, al esquema).
        EleccionDeImagenDelExamen::where('imagen_id', $imagen)->update(['imagen_id' => null]);
    }
}
