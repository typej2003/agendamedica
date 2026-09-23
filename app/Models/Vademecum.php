<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de medicamentos por médico — tabla legada real (ver la migración `create_vademecum_table`).
 * `recipes.codemedicina` apunta acá, dentro del mismo `reg_medico`.
 */
class Vademecum extends Model
{
    protected $table = 'vademecum';

    protected $fillable = [
        'reg_medico', 'codemedicina', 'nombregenerico', 'nombrecomercial', 'dosificacion', 'uso',
        'presentacion', 'concentracion', 'cada', 'durante', 'pvc', 'pvs', 'dosis', 'sico',
        'nombrecomercial1', 'nombrecomercial2', 'nombrecomercial3', 'totalre', 'sicome', 'sicome1',
        'sicome2', 'sicome3',
    ];
}
