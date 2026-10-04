<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Referencia de una consulta a otro médico (`ceduladoctor` → `doctores.cedula`) — tabla legada. Puede haber varias por consulta. */
class Referencia extends Model
{
    protected $table = 'referencia';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'ceduladoctor', 'referencia'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer', 'ceduladoctor' => 'integer'];
}
