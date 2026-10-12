<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La observación del conjunto de exámenes de una consulta — tabla legada `examen_obs` (WEB-5.4), una fila por `numhistoria` +
 * `numconsulta`. En el escritorio es el campo «Resumen clínico» de la solicitud de laboratorio.
 */
class ExamenObs extends Model
{
    protected $table = 'examen_obs';

    protected $fillable = ['reg_medico', 'numhistoria', 'numconsulta', 'observacion'];

    protected $casts = ['numhistoria' => 'integer', 'numconsulta' => 'integer'];
}
