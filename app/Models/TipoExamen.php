<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipos de examen por médico — tabla legada `tipos_examenes` (`codetipo` de tres dígitos en la práctica, `tipo` de
 * hasta 40: LABORATORIO, ESTUDIO DE IMAGENES, PREOPERATORIOS…). WEB-8.1b.
 */
class TipoExamen extends Model
{
    protected $table = 'tipos_examenes';

    protected $fillable = ['reg_medico', 'codetipo', 'tipo'];
}
