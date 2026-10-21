<?php

namespace App\Models;

use App\Pagos\Centavos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * **La liquidación T+1** de lo que la plataforma le recaudó a un médico (futuro: cuando el paciente paga
 * con un método de la plataforma o cuando la cita es online). Guarda los totales congelados y la tasa con
 * la que se pagó en VES.
 *
 * No se edita una liquidación pagada: se anula con nota y se genera otra, o se ajusta en la siguiente.
 */
class Liquidacion extends Model
{
    public const BORRADOR = 'borrador';
    public const CONFIRMADA = 'confirmada';
    public const PAGADA = 'pagada';
    public const ANULADA = 'anulada';

    public const ESTADOS = [self::BORRADOR, self::CONFIRMADA, self::PAGADA, self::ANULADA];

    protected $table = 'liquidaciones';

    protected $fillable = [
        'reg_medico', 'periodo_desde', 'periodo_hasta', 'total_bruto_usd_e2', 'total_comisiones_usd_e2',
        'total_neto_usd_e2', 'tasa', 'total_neto_ves_e2', 'moneda', 'estado', 'cuenta_bancaria_id',
        'pagada_el', 'referencia', 'nota', 'creado_por',
    ];

    protected $casts = [
        'periodo_desde' => 'date',
        'periodo_hasta' => 'date',
        'total_bruto_usd_e2' => 'integer',
        'total_comisiones_usd_e2' => 'integer',
        'total_neto_usd_e2' => 'integer',
        'tasa' => 'decimal:6',
        'total_neto_ves_e2' => 'integer',
        'pagada_el' => 'datetime',
    ];

    public function detalle(): HasMany
    {
        return $this->hasMany(LiquidacionPago::class, 'liquidacion_id');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function netoUsdTexto(): string
    {
        return Centavos::formatear($this->total_neto_usd_e2, 'USD');
    }

    public function netoVesTexto(): string
    {
        return Centavos::formatear($this->total_neto_ves_e2, 'VES');
    }
}
