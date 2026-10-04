<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Observación de la constancia de una consulta — tabla legada. El legado crea una fila vacía por cada consulta que se abre, así que la fila sola no significa que haya constancia (ROADMAP.md Paso 25). */
class ConstanciaObs extends Model
{
    protected $table = 'constancia_obs';

    protected $fillable = ['reg_medico', 'numhistoria', 'numconsulta', 'observacion', 'titulo', 'observacion01'];

    protected $casts = ['numhistoria' => 'integer', 'numconsulta' => 'integer'];
}
