<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **Una transacción bancaria de pago móvil o transferencia**, ya declarada por el paciente o registrada
 * por el consultorio.
 *
 * El `Pago` es la intención (cuánto se espera) y puede tener varias transacciones: un abono y su resto.
 * La plata que entró es la suma de las `aprobada`. La referencia es única por cuenta receptora, así que
 * declararla dos veces no acredita dos veces.
 */
class PagoTransaccion extends Model
{
    public const PENDIENTE = 'pendiente';
    public const APROBADA = 'aprobada';
    public const RECHAZADA = 'rechazada';
    public const NO_ENCONTRADA = 'no_encontrada';

    public const TIPO_PAGO_MOVIL = 'PM';
    public const TIPO_TRANSFERENCIA = 'TRF';

    public const ORIGEN_PACIENTE = 'paciente';
    public const ORIGEN_MEDICO = 'medico';
    public const ORIGEN_SISTEMA = 'sistema';

    protected $table = 'pago_transacciones';

    protected $fillable = [
        'pago_id', 'cuenta_bancaria_id', 'referencia', 'tipo', 'monto_e2', 'moneda',
        'estado', 'origen', 'respuesta', 'mensaje', 'confirmada_el',
    ];

    protected $casts = [
        'monto_e2' => 'integer',
        'respuesta' => 'array',
        'confirmada_el' => 'datetime',
    ];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function estaAprobada(): bool
    {
        return $this->estado === self::APROBADA;
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }
}
