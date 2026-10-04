<?php

// app/Models/Office.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El consultorio de un médico en una sede, con su configuración de agenda.
 *
 * Un médico tiene una office por cada lugar donde atiende, y **cada una puede trabajar distinto**:
 * el caso real es un hospital por orden de llegada en la mañana y una clínica con hora de cita en
 * la tarde. Por eso `modalidad` y `duracion_cita` viven acá y no en `evolucion`, que solo sabe de
 * un horario por médico.
 *
 * Los bloques de trabajo (qué días y en qué horas) están en `office_schedules`, una fila por
 * bloque. La columna `schedule` de esta tabla es texto libre descriptivo del esquema viejo
 * ("Lunes a Viernes de 8:00 AM a 4:00 PM") y **está obsoleta**: nadie la parsea.
 */
class Office extends Model
{
    public const MODALIDAD_HORA = 'hora_cita';
    public const MODALIDAD_ORDEN = 'orden_llegada';

    public const DURACION_CITA_POR_DEFECTO = 30;

    protected $fillable = [
        'medical_center_id',
        'medico_id',
        'reg_medico',
        'office_number',
        'phone',
        'modalidad',
        'duracion_cita',
        'activo',
        'schedule',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'duracion_cita' => 'integer',
    ];

    public function medicalCenter(): BelongsTo
    {
        return $this->belongsTo(MedicalCenter::class);
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(OfficeSchedule::class);
    }

    /** Los bloques de un día de la semana (lunes = 1), en orden. */
    public function bloquesDe(int $diaSemana)
    {
        return $this->schedules
            ->where('dia_semana', $diaSemana)
            ->sortBy(fn (OfficeSchedule $bloque) => OfficeSchedule::aMinutos($bloque->hora_inicio))
            ->values();
    }
}
