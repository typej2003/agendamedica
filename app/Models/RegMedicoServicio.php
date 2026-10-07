<?php

namespace App\Models;

use App\Casts\RestriccionesPlanCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servicio contratado por un `reg_medico`: una fila por contratación o renovación (el historial queda
 * solo). El vigente es el activo de mayor `vence_el` — lo resuelve App\Services\ServicioService.
 */
class RegMedicoServicio extends Model
{
    public const ORIGEN_REGISTRO = 'registro';
    public const ORIGEN_POWERBUILDER = 'powerbuilder';
    public const ORIGEN_COMPRA = 'compra';
    public const ORIGEN_MANUAL = 'manual';

    public const ACTIVO = 'activo';
    public const CANCELADO = 'cancelado';

    protected $table = 'reg_medico_servicio';

    protected $fillable = [
        'reg_medico', 'plan_id', 'plan_nombre', 'origen', 'inicia_el', 'vence_el',
        'monto_usd', 'restricciones', 'estado', 'nota',
    ];

    protected $casts = [
        'inicia_el' => 'date',
        'vence_el' => 'date',
        'monto_usd' => 'decimal:2',
        'restricciones' => RestriccionesPlanCast::class,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
