<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La observación de la solicitud de estudios de una consulta — tabla legada `radiologia_obs` (WEB-5.4), una fila por
 * `numhistoria` + `numconsulta`. En el escritorio es el «Resumen clínico» de la solicitud de estudios.
 */
class RadiologiaObs extends Model
{
    protected $table = 'radiologia_obs';

    protected $fillable = ['reg_medico', 'numhistoria', 'numconsulta', 'observacion'];

    protected $casts = ['numhistoria' => 'integer', 'numconsulta' => 'integer'];
}
