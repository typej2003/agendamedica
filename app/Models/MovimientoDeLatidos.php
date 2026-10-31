<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada del **libro de Latidos**. Es inmutable: los créditos suman, los descuentos y ajustes restan.
 *
 * `clave_idempotencia` es única: el webhook repetido, el reintento del OTP y el comando de conciliación no
 * pueden acreditar ni descontar dos veces. `disponibles_antes`/`disponibles_despues` dejan la foto del
 * movimiento sin tener que recalcular el libro.
 */
class MovimientoDeLatidos extends Model
{
    public const COMPRA = 'compra';
    public const REFERIDO = 'referido';
    public const PROMOCION = 'promocion';
    public const DESCUENTO_PLAN = 'descuento_plan';
    public const AJUSTE = 'ajuste';
    public const REVERSO = 'reverso';

    protected $table = 'movimientos_de_latidos';

    protected $fillable = [
        'latido_id', 'tipo', 'latidos', 'disponibles_antes', 'disponibles_despues',
        'clave_idempotencia', 'pago_id', 'referido_id', 'autor_id', 'concepto', 'nota',
    ];

    protected $casts = [
        'latidos' => 'integer',
        'disponibles_antes' => 'integer',
        'disponibles_despues' => 'integer',
    ];

    public function latido(): BelongsTo
    {
        return $this->belongsTo(Latido::class, 'latido_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
