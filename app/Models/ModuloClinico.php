<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un módulo clínico de la web (agenda, pacientes, consulta, ecografías…). El catálogo lo comparten
 * todas las especialidades; qué módulos tiene cada una está en `especialidad_modulo`.
 *
 * El slug es el que usan los manifiestos (`config/especialidades/*.php`) y el menú de la web.
 */
class ModuloClinico extends Model
{
    protected $table = 'modulos_clinicos';

    protected $fillable = ['slug', 'nombre', 'orden', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'especialidad_modulo', 'modulo_id', 'specialty_id')
            ->withPivot(['implementado', 'visible_para', 'orden'])
            ->withTimestamps();
    }
}
