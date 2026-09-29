<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carga inicial completa de un médico desde el escritorio PowerBuilder.
 * Ver App\Services\CargaInicialService.
 */
class SyncCarga extends Model
{
    public const EN_CURSO = 'en_curso';
    public const COMPLETA = 'completa';

    protected $table = 'sync_cargas';

    protected $fillable = ['reg_medico', 'medico_id', 'estado', 'iniciada_at', 'finalizada_at'];

    protected $casts = [
        'iniciada_at'   => 'datetime',
        'finalizada_at' => 'datetime',
    ];

    public function tablas(): HasMany
    {
        return $this->hasMany(SyncCargaTabla::class);
    }
}
