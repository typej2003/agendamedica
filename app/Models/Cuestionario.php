<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** **El cuestionario por aparatos de una consulta** (WEB-12.2): la tabla `cuestionario` de pediatría (clave `nrohistoria` + `nroconsulta`). */
class Cuestionario extends Model
{
    protected $table = 'cuestionario';

    protected $fillable = [
        'reg_medico', 'nrohistoria', 'nroconsulta', 'digestivo', 'respiratorio', 'circulatorio', 'musculoesqueletico', 'nerviosa', 'urinario',
        'reproductor', 'sentidos', 'denticion', 'lactancia', 'otras',
    ];
}
