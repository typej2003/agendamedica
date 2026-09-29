<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Informe médico de una consulta — tabla legada. */
class Informe extends Model
{
    protected $table = 'informe';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'para', 'descripcion', 'fe_cha'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer', 'fe_cha' => 'date'];
}
