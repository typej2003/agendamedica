<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El **vínculo de un referido**: quién refirió a quién, con cuántos Latidos se premió (congelados) y en qué
 * estado quedó.
 *
 * `referido_reg_medico` es único: un consultorio sólo puede ser referido una vez. La fila nace al aprobar
 * la solicitud; antes de eso el código vive en `solicitudes_medico`.
 */
class Referido extends Model
{
    public const ACREDITADO = 'acreditado';
    public const RECHAZADO = 'rechazado';
    public const ANULADO = 'anulado';

    public const ESTADOS = [self::ACREDITADO, self::RECHAZADO, self::ANULADO];

    protected $table = 'referidos';

    protected $fillable = [
        'codigo_id', 'codigo', 'referente_reg_medico', 'referido_reg_medico', 'solicitud_id', 'medico_id',
        'estado', 'latidos_referente', 'latidos_referido', 'acreditado_el', 'anulado_el', 'anulado_por', 'motivo',
    ];

    protected $casts = [
        'latidos_referente' => 'integer',
        'latidos_referido' => 'integer',
        'acreditado_el' => 'datetime',
        'anulado_el' => 'datetime',
    ];

    public function codigo(): BelongsTo
    {
        return $this->belongsTo(CodigoDeReferido::class, 'codigo_id');
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudMedico::class, 'solicitud_id');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    public function referente(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'referente_reg_medico', 'reg_medico');
    }

    public function referido(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'referido_reg_medico', 'reg_medico');
    }

    public function estaAcreditado(): bool
    {
        return $this->estado === self::ACREDITADO;
    }
}
