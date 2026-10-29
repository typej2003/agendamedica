<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitación de un médico a otro para unirse al equipo de un `reg_medico` (2026-10-28).
 *
 * No es un acceso: el acceso se crea recién al aceptar, con la fila de `medico_registros`. La invitación
 * guarda el correo destinatario, el token del enlace, el estado y el vencimiento.
 */
class InvitacionDeMedico extends Model
{
    public const PENDIENTE = 'pendiente';
    public const ACEPTADA = 'aceptada';
    public const RECHAZADA = 'rechazada';
    public const REVOCADA = 'revocada';
    public const VENCIDA = 'vencida';

    /** Días que dura una invitación. */
    public const DIAS_DE_VIGENCIA = 30;

    protected $table = 'invitaciones_de_medico';

    protected $fillable = [
        'reg_medico', 'email', 'medico_id', 'invitada_por', 'token', 'estado',
        'expira_el', 'aceptada_el',
    ];

    protected $casts = [
        'expira_el' => 'datetime',
        'aceptada_el' => 'datetime',
    ];

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    public function invitador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitada_por');
    }

    /** ¿Sigue pendiente y no venció? */
    public function estaVigente(): bool
    {
        return $this->estado === self::PENDIENTE && $this->expira_el !== null && $this->expira_el->isFuture();
    }

    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::PENDIENTE);
    }

    public function scopeParaEmail(Builder $consulta, string $email): Builder
    {
        return $consulta->where('email', mb_strtolower(trim($email)));
    }

    /** El estado que se muestra: una pendiente vencida se cuenta como vencida. */
    public function estadoLegible(): string
    {
        if ($this->estado === self::PENDIENTE && $this->expira_el !== null && $this->expira_el->isPast()) {
            return self::VENCIDA;
        }

        return $this->estado;
    }
}
