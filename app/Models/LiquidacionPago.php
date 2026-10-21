<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El **detalle congelado** de una liquidación: qué pago entró, con qué bruto, comisiones y neto. `pago_id`
 * es único en la tabla (lo garantiza la migración): un pago no puede entrar en dos liquidaciones, que es
 * el candado contra el doble pago del job de corte.
 */
class LiquidacionPago extends Model
{
    protected $table = 'liquidacion_pagos';

    protected $fillable = [
        'liquidacion_id', 'pago_id', 'bruto_usd_e2', 'comisiones_usd_e2', 'neto_usd_e2', 'neto_ves_e2',
    ];

    protected $casts = [
        'bruto_usd_e2' => 'integer',
        'comisiones_usd_e2' => 'integer',
        'neto_usd_e2' => 'integer',
        'neto_ves_e2' => 'integer',
    ];

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
