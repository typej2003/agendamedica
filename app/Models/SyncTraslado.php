<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un registro que la carga inicial tuvo que mover para que **el escritorio mande** (ver
 * `App\Sync\Escritorio\Traslados`).
 *
 * Es el rastro y la red de seguridad: guarda los números de antes, los de la parada y los finales, y una
 * copia de la fila tal como estaba. Nada se borra sin dejar su copia acá.
 */
class SyncTraslado extends Model
{
    public const PENDIENTE = 'pendiente';
    public const REACOMODADO = 'reacomodado';
    public const A_REVISAR = 'a_revisar';

    protected $table = 'sync_traslados';

    protected $fillable = [
        'reg_medico', 'sync_carga_id', 'tabla', 'registro_id', 'nivel', 'motivo',
        'clave_original', 'clave_temporal', 'clave_final', 'copia', 'estado',
    ];

    public function carga(): BelongsTo
    {
        return $this->belongsTo(SyncCarga::class, 'sync_carga_id');
    }

    /** La copia de la fila (o de las filas) tal como estaba, para el reporte y la recuperación a mano. */
    public function copiaDecodificada(): mixed
    {
        return $this->copia === null ? null : json_decode($this->copia, true);
    }
}
