<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Cola extends Model
{
    use HasFactory;

    protected $table = 'cola';

    protected $fillable = [
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

    /**
     * Relación con el modelo MedicoRegistro mediante la clave reg_medico.
     */
    public function medicoRegistro(): BelongsTo
    {
        return $this->belongsTo(MedicoRegistro::class, 'reg_medico', 'reg_medico');
    }

    /**
     * Relación indirecta con el modelo Medico a través de MedicoRegistro.
     */
    public function medico(): HasOneThrough
    {
        return $this->hasOneThrough(
            Medico::class,
            MedicoRegistro::class,
            'reg_medico', // Clave foránea en medico_registros
            'id',         // Clave primaria en medicos
            'reg_medico', // Clave local en cola
            'medico_id'   // Clave foránea en medico_registros hacia medicos
        );
    }

    /**
     * Relación con la tabla pacientes mediante el número de historia.
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'numhistoria', 'numhistoria');
    }
}