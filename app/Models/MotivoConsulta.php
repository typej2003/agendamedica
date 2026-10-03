<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de motivos de consulta por médico — tabla legada (`codemotivo` de 4 caracteres,
 * correlativo "0001", "0002"…). No confundir con `MotivoCita` (el motivo de la **cita**, con precio).
 */
class MotivoConsulta extends Model
{
    protected $table = 'motivos_consulta';

    protected $fillable = ['reg_medico', 'codemotivo', 'descripcion'];
}
