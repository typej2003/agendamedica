<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`archivos_historia`**: los PDF que el cardiólogo adjunta a la consulta (el ítem «Archivos-PDF» de CardioReport).
 *
 * Una fila por archivo, con la clave del legado `nrohistoria` + `nroconsulta` y la ruta del archivo en `archivo`. El
 * escritorio guarda una ruta de Windows; la web guarda la ruta relativa del disco privado `clinica`.
 */
class ArchivoDeHistoria extends Model
{
    protected $table = 'archivos_historia';

    protected $guarded = ['id'];
}
