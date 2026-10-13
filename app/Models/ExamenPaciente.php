<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un examen pedido en una consulta — tabla legada `examen_paciente` (WEB-5.4). Se llavea por `nrohistoria` + `nroconsulta` +
 * `codeexamen`; `resultado` y `vinculante` existen en el esquema pero están vacías en toda la base real, y la web no las escribe.
 */
class ExamenPaciente extends Model
{
    protected $table = 'examen_paciente';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'codeexamen', 'resultado', 'vinculante'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer'];
}
