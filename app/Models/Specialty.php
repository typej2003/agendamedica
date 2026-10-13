<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Specialty extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'codigo', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Specialty $specialty) {
            if (empty($specialty->slug)) {
                $specialty->slug = Str::slug($specialty->name);
            }
        });
    }

    public function medicos()
    {
        return $this->belongsToMany(Medico::class, 'medico_specialty', 'specialty_id', 'medico_id');
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(ModuloClinico::class, 'especialidad_modulo', 'specialty_id', 'modulo_id')
            ->using(EspecialidadModulo::class)
            ->withPivot(['implementado', 'visible_para', 'orden'])
            ->withTimestamps();
    }

    /**
     * Módulos que la web puede mostrarle a este rol: los que ya están **implementados**
     * (`especialidad_modulo.implementado`) y cuyo `visible_para` incluya alguno de sus roles.
     *
     * Lo declarado en el manifiesto pero todavía no construido **no aparece**: la navegación se va
     * llenando a medida que cada módulo se termina (decisión del usuario, 2026-10-08).
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

                // Sin restricción declarada, lo ve todo el consultorio (médico y secretaría).
                return empty($visibles) || array_intersect((array) $visibles, $roles) !== [];
            })
            ->sortBy(fn (ModuloClinico $modulo) => sprintf('%05d-%05d-%s', $modulo->pivot->orden, $modulo->orden, $modulo->nombre))
            ->values();
    }
}
