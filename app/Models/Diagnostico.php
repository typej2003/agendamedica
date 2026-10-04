<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catálogo de diagnósticos por médico — tabla legada. */
class Diagnostico extends Model
{
    protected $table = 'diagnosticos';

    protected $fillable = ['reg_medico', 'codediagnostico', 'descripcion'];
}
