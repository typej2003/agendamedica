<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Motivos de una consulta — tabla legada. La descripción sale de `motivos_consulta` por `codemotivo`
 * (las columnas `descripcion` y `detalle` de esta tabla las deja vacías el escritorio).
 */
class MotivoConsultaPaciente extends Model
{
    protected $table = 'motivo_consulta_paciente';

    protected $fillable = ['reg_medico', 'codemotivo', 'nrohistoria', 'nroconsulta', 'descripcion', 'detalle'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer'];
}
