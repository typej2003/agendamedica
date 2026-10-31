<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Los Latidos **reservados** por un pago que todavía no se aprobó. No descuentan: mientras están
 * `activa`, esos Latidos no se pueden usar en otro pago. Al aprobarse el pago pasan a `consumida` (y se
 * descuentan del libro); si el pago se rechaza, vence o se cancela, pasan a `liberada`.
 */
class RetencionDeLatidos extends Model
{
    public const ACTIVA = 'activa';
    public const CONSUMIDA = 'consumida';
    public const LIBERADA = 'liberada';

    protected $table = 'retenciones_de_latidos';

    protected $fillable = [
        'latido_id', 'pago_id', 'latidos', 'estado', 'expira_el',
    ];

    protected $casts = [
        'latidos' => 'integer',
        'expira_el' => 'datetime',
    ];

    public function latido(): BelongsTo
    {
        return $this->belongsTo(Latido::class, 'latido_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function estaActiva(): bool
    {
        return $this->estado === self::ACTIVA;
    }
}
