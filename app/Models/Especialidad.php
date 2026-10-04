<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catálogo de especialidades del legado, por médico (`doctores.codeespecial` apunta acá). */
class Especialidad extends Model
{
    protected $table = 'especial';

    protected $fillable = ['reg_medico', 'codeespecial', 'especialidad'];
}
