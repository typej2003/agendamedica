<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carga inicial completa de un médico desde el escritorio PowerBuilder.
 * Ver App\Services\CargaInicialService.
 *
 * `modo` dice cómo se aplica: `limpia` (el consultorio no tenía datos) o `mezcla` (ya tenía y el
 * escritorio manda: lo que ocupaba un número se mueve al final). Ver App\Sync\Escritorio\Traslados.
 */
class SyncCarga extends Model
{
    public const EN_CURSO = 'en_curso';
    public const COMPLETA = 'completa';

    public const MODO_LIMPIA = 'limpia';
    public const MODO_MEZCLA = 'mezcla';

    protected $table = 'sync_cargas';

    protected $fillable = ['reg_medico', 'medico_id', 'estado', 'modo', 'iniciada_at', 'finalizada_at'];

    protected $casts = [
        'iniciada_at'   => 'datetime',
        'finalizada_at' => 'datetime',
    ];

    public function tablas(): HasMany
    {
        return $this->hasMany(SyncCargaTabla::class);
    }

    public function traslados(): HasMany
    {
        return $this->hasMany(SyncTraslado::class);
    }

    public function esMezcla(): bool
    {
        return $this->modo === self::MODO_MEZCLA;
    }
}
