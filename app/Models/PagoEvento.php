<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un evento de la **bitácora** de un pago: el webhook que llegó, la consulta que hizo el job, la acción
 * manual de un administrador. El payload se guarda saneado (sin OTP ni tokens).
 */
class PagoEvento extends Model
{
    public const ORIGEN_WEBHOOK = 'webhook';
    public const ORIGEN_CONSULTA = 'consulta';
    public const ORIGEN_MANUAL = 'manual';
    public const ORIGEN_SISTEMA = 'sistema';

    protected $table = 'pagos_eventos';

    protected $fillable = [
        'pago_id', 'origen', 'evento', 'payload', 'estado_anterior', 'estado_nuevo', 'ip',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
