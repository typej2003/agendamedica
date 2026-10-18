<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Las **mediciones de una consulta** del escritorio de pediatría (WEB-7.4.3a) — tabla legada (`percentiles_paciente`, una fila por consulta).
 * La clave del legado es `hhistoria` (el `numhistoria`) + `hconsulta` (el `nroconsulta`). Peso en kg, talla y perímetros en cm.
 */
class PercentilesPaciente extends Model
{
    protected $table = 'percentiles_paciente';

    protected $fillable = [
        'reg_medico', 'hhistoria', 'hconsulta', 'peso', 'talla', 'c_cefalica', 'c_brazo', 'pulso',
        'p_a_sistolica', 'p_a_diastolica', 'fecha', 'imc',
    ];

    protected $casts = [
        'hhistoria' => 'integer',
        'hconsulta' => 'integer',
        'peso' => 'float',
        'talla' => 'float',
        'c_cefalica' => 'float',
        'c_brazo' => 'float',
        'pulso' => 'float',
        'p_a_sistolica' => 'float',
        'p_a_diastolica' => 'float',
        'imc' => 'float',
        'fecha' => 'date',
    ];
}
