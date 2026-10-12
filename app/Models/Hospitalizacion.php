<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Orden de hospitalización de una consulta — tabla legada (`hospitalizacion`, clave `nrohistoria` + `nroconsulta`).
 *
 * `indiprev` y `habitacion` existen en el esquema y las trae la hoja del escritorio, pero **ninguna pantalla del PowerBuilder las
 * escribe** (están vacías en las 119.299 filas de la base de desarrollo): la web tampoco las toca.
 */
class Hospitalizacion extends Model
{
    protected $table = 'hospitalizacion';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'diagprev', 'indiprev', 'dieta', 'habitacion', 'hospital'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer'];
}
