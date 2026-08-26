<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MedicoPaciente extends Pivot
{
    use HasFactory;

    protected $table = 'medico_pacientes';

    protected $fillable = [
        'medico_id',
        'paciente_id',
        'numhistoria',
    ];
}