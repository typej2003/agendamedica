<?php

namespace App\Models;

use App\Models\SyncChange;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bloque de trabajo de un consultorio: "los lunes de 8:00 a 12:00, hasta 20 pacientes".
 *
 * Ver el porqué de la tabla (en vez de un JSON en `offices`) en su migración.
 *
 * Los bloques se administran desde la web (WEB-2.8b.3, `/clinica/consultorios/{id}/horarios`) y el
 * móvil los recibe como espejo de solo lectura: por eso el borrado deja rastro en `sync_changes`.
 */
class OfficeSchedule extends Model
{
    protected $table = 'office_schedules';

    /** Los días como los nombra la agenda. La clave es la convención ISO de `dia_semana` (lunes = 1). */
    public const DIAS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

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

    /**
     * Deja rastro en `sync_changes` antes de borrarse: el delta del móvil manda la tabla completa por
     * `updated_at` (una fila borrada no se puede "actualizar"), así que sin esto el teléfono seguiría
     * mostrando un bloque que ya no existe. Es el mismo mecanismo que usa el borrado de una cita
     * (`Cola::booted()`).
     */
    protected static function booted()
    {
        static::deleting(function (OfficeSchedule $bloque) {
            SyncChange::create([
                'reg_medico'  => $bloque->reg_medico,
                'table_name'  => 'office_schedules',
                'record_id'   => $bloque->id,
                'operation'   => 'deleted',
                'occurred_at' => now(),
                'source'      => 'web',
            ]);
        });
    }

    /** El nombre del día, para mostrarlo (el `dia_semana` crudo no le dice nada a nadie). */
    public function nombreDelDia(): string
    {
        return self::DIAS[(int) $this->dia_semana] ?? '—';
    }

    /** "8:00 AM a 12:00 PM": hora del consultorio en 12 h, sin los segundos que trae la columna `time`. */
    public function rango(): string
    {
        return HoraLocal::rango($this->hora_inicio, $this->hora_fin);
    }

    /**
     * ¿Este bloque se cruza con otro del mismo día? El fin es **exclusivo**: 8:00-12:00 y 12:00-16:00
     * son contiguos, no superpuestos (es la misma convención que `contiene()`).
     *
     * Compara horas y día, no ids: quien la usa ya excluyó la fila que se está editando.
     */
    public function seCruzaCon(?OfficeSchedule $otro): bool
    {
        if ($otro === null || (int) $otro->dia_semana !== (int) $this->dia_semana) {
            return false;
        }

        $inicio = self::aMinutos($this->hora_inicio);
        $fin = self::aMinutos($this->hora_fin);
        $otroInicio = self::aMinutos($otro->hora_inicio);
        $otroFin = self::aMinutos($otro->hora_fin);

        if ($inicio === null || $fin === null || $otroInicio === null || $otroFin === null) {
            return false;
        }

        return $inicio < $otroFin && $otroInicio < $fin;
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
