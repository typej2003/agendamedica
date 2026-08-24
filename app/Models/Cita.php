<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasCompositePrimaryKey;

class Cita extends Model
{
    use HasFactory;

    protected $table = 'citas';
    
    // Al ser una clave primaria compuesta, se deshabilita la autoincrementación
    public $incrementing = false;
    protected $primaryKey = ['fecha', 'numhistoria'];

    protected $fillable = [
        'fecha',
        'numhistoria',
        'numorden',
        'atendido',
        'estado',
        'turno',
        'motivo',
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
        'atendido' => 'integer',
        'estado' => 'integer',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'numhistoria', 'numhistoria');
    }

    public function motivoCita(): BelongsTo
    {
        return $this->belongsTo(MotivoCita::class, 'tipo', 'codigo');
    }
}