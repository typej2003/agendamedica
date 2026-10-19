<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * **Una subespecialidad (postgrado) de una especialidad** (2026-10-19).
 *
 * Nefrología pediátrica no es una especialidad: es el postgrado que un pediatra hizo dentro de Pediatría.
 * Un médico puede tener varios postgrados de la misma especialidad (nefrología y neumología pediátricas) y
 * los de la especialidad que está trabajando **se suman** para encender módulos y secciones.
 *
 * El catálogo lo siembran los manifiestos (`config/especialidades/<especialidad>.php` →
 * `subespecialidades`), no se da de alta a mano: el `slug` es la clave estable que usan el manifiesto, la
 * siembra y las pruebas.
 */
class Subspecialty extends Model
{
    protected $table = 'subespecialidades';

    protected $fillable = ['specialty_id', 'slug', 'nombre', 'codigo', 'orden', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
        'specialty_id' => 'integer',
    ];

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id');
    }

    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'medico_subespecialidad', 'subespecialidad_id', 'medico_id');
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(ModuloClinico::class, 'subespecialidad_modulo', 'subespecialidad_id', 'modulo_id')
            ->using(SubespecialidadModulo::class)
            ->withPivot(['implementado', 'visible_para', 'orden'])
            ->withTimestamps();
    }

    /**
     * Los módulos del **menú** que este postgrado enciende para esos roles: los implementados y cuyo
     * `visible_para` incluya alguno de los roles. Misma regla que `Specialty::modulosVisibles()`.
     *
     * @param  array<int, string>  $roles
     * @return Collection<int, ModuloClinico>
     */
    public function modulosVisibles(array $roles): Collection
    {
        return $this->modulos
            ->filter(fn (ModuloClinico $modulo) => $modulo->activo && $modulo->pivot->implementado)
            ->filter(function (ModuloClinico $modulo) use ($roles) {
                $visibles = $modulo->pivot->visible_para;

                return empty($visibles) || array_intersect((array) $visibles, $roles) !== [];
            })
            ->values();
    }
}
