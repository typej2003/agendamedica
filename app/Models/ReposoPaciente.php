<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reposo médico de una consulta — tabla legada. Hasta = `fdesde + numdias - 1`, reintegro = `fdesde + numdias` (`d_reposo_1` del legado). */
class ReposoPaciente extends Model
{
    protected $table = 'reposo_paciente';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'codereposo', 'fdesde', 'numdias', 'obser_reposo'];

    protected $casts = [
        'nrohistoria' => 'integer',
        'nroconsulta' => 'integer',
        'fdesde' => 'date',
        'numdias' => 'integer',
    ];
}
