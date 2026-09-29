<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Diagnósticos de una consulta — tabla legada. La descripción sale de `diagnosticos` por `codediagnostico`. */
class DiagnosticoPaciente extends Model
{
    protected $table = 'diagnostico_paciente';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'codediagnostico', 'detalle_diagnostco', 'orden'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer', 'orden' => 'integer'];
}
