<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dieta / recomendaciones de una consulta — tabla legada (`dieta_paciente`). Ojo con el nombre: la columna de la historia se
 * llama **`histroria`** (con la errata del esquema) y la de la consulta **`consulta`**, y el número que guardan es el
 * `nroconsulta`, no el id.
 */
class DietaPaciente extends Model
{
    protected $table = 'dieta_paciente';

    protected $fillable = ['reg_medico', 'histroria', 'consulta', 'cod_dieta', 'descripcion', 'ejecutada', 'calorias', 'dieta'];

    protected $casts = ['histroria' => 'integer', 'consulta' => 'integer', 'calorias' => 'integer'];
}
