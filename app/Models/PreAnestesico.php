<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`pre_aneste`**: la evaluación pre-operatoria de una consulta (cirugía pediátrica), **una fila por consulta** con
 * la clave del legado `historia` + `consulta`.
 *
 * Las tres columnas son `char` cortas: `tipo` de un carácter, `resultado` de 200 y `emergencia` de un carácter
 * (`S`/`N`). El mapa está en `App\Clinica\Consulta\EvaluacionPreoperatoria`.
 */
class PreAnestesico extends Model
{
    protected $table = 'pre_aneste';

    protected $fillable = ['reg_medico', 'historia', 'consulta', 'tipo', 'resultado', 'emergencia'];

    protected $casts = ['historia' => 'integer', 'consulta' => 'integer'];
}
