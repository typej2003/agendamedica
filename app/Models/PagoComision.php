<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del **desglose de comisiones** de un pago. `secuencia` deja escrito el orden de la cascada
 * (1 = método sobre el bruto, 2 = cita online sobre el remanente) y `base_*` la base de cada paso, para
 * que el recibo se pueda reproducir aunque después cambien las reglas.
 */
class PagoComision extends Model
{
    protected $table = 'pago_comisiones';

    protected $fillable = [
        'pago_id', 'secuencia', 'tipo', 'base_usd_e2', 'base_ves_e2', 'porcentaje_bp',
        'monto_usd_e2', 'monto_ves_e2', 'regla_id', 'detalle',
    ];

    protected $casts = [
        'secuencia' => 'integer',
        'base_usd_e2' => 'integer',
        'base_ves_e2' => 'integer',
        'porcentaje_bp' => 'integer',
        'monto_usd_e2' => 'integer',
        'monto_ves_e2' => 'integer',
    ];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaDeComision::class, 'regla_id');
    }
}
