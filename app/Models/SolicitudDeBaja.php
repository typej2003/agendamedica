<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **Una solicitud de baja de cuenta** (2026-10-30). Ver la migración `create_solicitudes_de_baja_table`
 * para los cuatro estados y para por qué la fila sobrevive al borrado de la cuenta.
 */
class SolicitudDeBaja extends Model
{
    protected $table = 'solicitudes_de_baja';

    protected $fillable = [
        'user_id',
        'email',
        'token',
        'estado',
        'confirmada_en',
        'cancelada_en',
        'ejecutada_en',
        'ejecutar_despues_de',
        'motivo',
    ];

    protected $casts = [
        'confirmada_en'       => 'datetime',
        'cancelada_en'        => 'datetime',
        'ejecutada_en'        => 'datetime',
        'ejecutar_despues_de' => 'datetime',
    ];

    public const PENDIENTE = 'pendiente';
    public const PROGRAMADA = 'programada';
    public const CANCELADA = 'cancelada';
    public const EJECUTADA = 'ejecutada';

    public const MOTIVO_INICIO_DE_SESION = 'inicio-de-sesion';
    public const MOTIVO_ENLACE = 'enlace';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    public function estaProgramada(): bool
    {
        return $this->estado === self::PROGRAMADA;
    }

    /** Días que le quedan al usuario para echarse atrás (0 si ya venció). */
    public function diasRestantes(): int
    {
        if ($this->ejecutar_despues_de === null) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInDays($this->ejecutar_despues_de, false)));
    }
}
