<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una notificación enviada al paciente por una cita puntual (ver la migración para el porqué de
 * no reusar `sms_enviados` del legado).
 */
class NotificacionCita extends Model
{
    use HasFactory;

    public const CANAL_WHATSAPP = 'whatsapp';
    public const CANAL_SMS = 'sms';

    public const ESTADO_ENVIADA = 'enviada';
    public const ESTADO_FALLIDA = 'fallida';

    protected $table = 'notificaciones_cita';

    protected $fillable = [
        'reg_medico',
        'cola_id',
        'canal',
        'destino',
        'plantilla',
        'mensaje',
        'estado',
        'respuesta',
        'enviada_por',
    ];

    public function cola(): BelongsTo
    {
        return $this->belongsTo(Cola::class, 'cola_id');
    }
}
