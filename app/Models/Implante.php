<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`implante`**: la solicitud de implante de una consulta de traumatología (clave `historia` + `consulta`, las columnas
 * del legado). La fila es la misma para la cadera, la rodilla y el texto libre: el escritorio elige la hoja al imprimir.
 */
class Implante extends Model
{
    protected $table = 'implante';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_reporte' => 'date',
        'fecha_opera' => 'date',
    ];
}
