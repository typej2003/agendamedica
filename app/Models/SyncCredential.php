<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credencial de sincronización de un equipo del escritorio (PowerBuilder 10).
 *
 * Reemplaza a la `X-API-KEY` hardcodeada en el `.pbl`: una credencial por PC, con vencimiento
 * y revocación. El token en claro no se guarda nunca — solo su SHA-256.
 *
 * @property string      $reg_medico
 * @property string      $machine_label
 * @property string|null $machine_host
 * @property bool        $bound_to_machine
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class SyncCredential extends Model
{
    protected $table = 'sync_credentials';

    protected $fillable = [
        'reg_medico',
        'machine_label',
        'machine_host',
        'bound_to_machine',
        'token_hash',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $casts = [
        'bound_to_machine' => 'boolean',
        'expires_at'       => 'datetime',
        'revoked_at'       => 'datetime',
        'last_used_at'     => 'datetime',
    ];

    /** Hash determinista de un token en claro. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Genera un token nuevo. Formato reconocible para poder buscarlo por prefijo en logs. */
    public static function generarToken(): string
    {
        return 'ddr_sync_' . bin2hex(random_bytes(32));
    }

    public function estaVencida(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function estaRevocada(): bool
    {
        return $this->revoked_at !== null;
    }

    public function estaActiva(): bool
    {
        return ! $this->estaVencida() && ! $this->estaRevocada();
    }

    /** Días que faltan para el vencimiento (negativo si ya venció). */
    public function diasParaVencer(): ?int
    {
        return $this->expires_at ? (int) now()->diffInDays($this->expires_at, false) : null;
    }

    public function scopeActivas($query)
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
