<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MedicoMedicalCenter extends Pivot
{
    use HasFactory;

    protected $table = 'medico_medical_center';

    protected $fillable = [
        'medico_id',
        'medical_center_id',
        'reg_medico',
    ];
}