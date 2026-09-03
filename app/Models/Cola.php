<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cola extends Model
{
    use HasFactory;

    protected $table = 'cola';

    protected $fillable = [
        'medico_id',
        'reg_medico',
        'fecha',
        'numhistoria',
        'paciente_sinhistoria_id',
        'numorden',
        'atendido',
        'estado',
        'turno',
        'motivo',
        'monto',
        'hora_ini',
        'hora_fin',
        'tiempo',
        'tipo',
        'conse',
        'sms',
        'sms_text',
        'medico',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function medicoUser()
    {
        return $this->belongsTo(User::class, 'medico_id');
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'numhistoria', 'numhistoria');
    }

}
 