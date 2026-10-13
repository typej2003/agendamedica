<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un saludo de cumpleaños mandado (o intentado) a un paciente (WEB-2.9). Ver la migración para el
 * porqué de una tabla propia en vez de reusar `notificaciones_cita` (esa es del recordatorio **de una
 * cita** y su `cola_id` es obligatorio).
 *
 * `enviada_por` queda en `null` cuando lo mandó el servidor solo (el comando del scheduler): no hay
 * usuario al que atribuirlo, y dejar el del último que tocó la pantalla sería mentir.
 */
class NotificacionCumple extends Model
{
    use HasFactory;

    protected $table = 'notificaciones_cumple';

    public const ESTADO_ENVIADA = 'enviada';
    public const ESTADO_FALLIDA = 'fallida';

    protected $fillable = [
        'reg_medico',
        'paciente_id',
        'fecha_cumple',
        'canal',
        'destino',
        'plantilla',
        'mensaje',
        'estado',
        'respuesta',
        'enviada_por',
    ];

    protected $casts = [
        'fecha_cumple' => 'date',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }
}
