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

    /**
     * SMS por proveedor desde el servidor (Twilio, WEB-2.6). El móvil sigue mandando el suyo con el
     * SMS nativo de Android y **no** lo registra acá: es el teléfono el que responde por ese envío.
     */
    public const CANAL_SMS = 'sms';

    /** Correo con el SMTP del servidor y el médico de la cita como remitente (WEB-2.6). */
    public const CANAL_CORREO = 'correo';

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
