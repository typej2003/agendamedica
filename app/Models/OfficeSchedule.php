<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bloque de trabajo de un consultorio: "los lunes de 8:00 a 12:00, hasta 20 pacientes".
 *
 * Ver el porqué de la tabla (en vez de un JSON en `offices`) en su migración.
 */
class OfficeSchedule extends Model
{
    protected $table = 'office_schedules';

    protected $fillable = [
        'office_id',
        'reg_medico',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'cupo',
    ];

    protected $casts = [
        'dia_semana' => 'integer',
        'cupo' => 'integer',
    ];

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** ¿Esta hora (`HH:MM` o `HH:MM:SS`) cae dentro del bloque? */
    public function contiene(?string $hora): bool
    {
        if ($hora === null || $hora === '') {
            return false;
        }

        $minutos = self::aMinutos($hora);
        $inicio = self::aMinutos($this->hora_inicio);
        $fin = self::aMinutos($this->hora_fin);

        if ($minutos === null || $inicio === null || $fin === null) {
            return false;
        }

        // El fin es exclusivo: una cita a las 12:00 en un bloque 8:00-12:00 pertenece al
        // siguiente, no a este — es el borde donde el médico ya se fue.
        return $minutos >= $inicio && $minutos < $fin;
    }

    public static function aMinutos(?string $hora): ?int
    {
        if ($hora === null) {
            return null;
        }
        $partes = explode(':', $hora);
        if (count($partes) < 2) {
            return null;
        }

        return ((int) $partes[0]) * 60 + (int) $partes[1];
    }
}
