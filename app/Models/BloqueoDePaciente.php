<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un bloqueo de paciente-cuenta con su alcance (cuenta, teléfono o cédula). Ver la migración
 * `create_bloqueos_de_paciente_table` y `App\Paciente\BloqueoDePacientes`.
 */
class BloqueoDePaciente extends Model
{
    protected $table = 'bloqueos_de_paciente';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'creado_en' => 'datetime',
        'vence_en' => 'datetime',
        'levantado_en' => 'datetime',
    ];

    /** Sin levantar y sin vencer. */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('levantado_en')
            ->where(fn (Builder $q) => $q->whereNull('vence_en')->orWhere('vence_en', '>', now()));
    }
}
