<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * **Los Latidos de un consultorio** (`reg_medico`).
 *
 * No es una wallet ni un saldo en dinero: es una **unidad de descuento promocional** (100 Latidos = USD 1
 * de descuento). `disponibles` es una caché del libro; la verdad es la suma de `movimientos_de_latidos`.
 * La fila se crea sola la primera vez que se necesita (`App\Latidos\Latidos::para`).
 *
 * `renovacion_automatica` nace encendida (decisión del 2026-10-30): si hay Latidos suficientes, el plan
 * se paga solo el día del vencimiento. El médico puede apagarla.
 */
class Latido extends Model
{
    protected $table = 'latidos';

    protected $fillable = [
        'reg_medico', 'disponibles', 'renovacion_automatica', 'renovacion_plan_id', 'renovacion_meses',
    ];

    protected $casts = [
        'disponibles' => 'integer',
        'renovacion_automatica' => 'boolean',
        'renovacion_meses' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'renovacion_plan_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoDeLatidos::class, 'latido_id')->orderBy('id');
    }

    public function retenciones(): HasMany
    {
        return $this->hasMany(RetencionDeLatidos::class, 'latido_id');
    }
}
