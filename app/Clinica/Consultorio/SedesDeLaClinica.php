<?php

namespace App\Clinica\Consultorio;

use App\Models\City;
use App\Models\MedicalCenter;
use Illuminate\Support\Collection;

/**
 * Lectura de las **sedes** de la clínica (`medical_centers`): el lugar donde atienden los médicos. Es del
 * catálogo de la clínica, no de un médico (decisión del 2026-10-09), así que no depende del contexto.
 */
class SedesDeLaClinica
{
    /**
     * El listado con los dos datos que hacen falta para decidir: dónde está y **cuántos consultorios
     * activos** tiene (el consultorio cuelga de la sede). Una sede sin consultorios es una sede cargada y
     * sin usar, que es justo lo que hay que ver antes de desactivarla.
     *
     * @return Collection<int,MedicalCenter>
     */
    public function listar(bool $verTodas): Collection
    {
        return MedicalCenter::with('city')
            ->withCount(['offices as consultorios_activos' => fn ($consulta) => $consulta->where('activo', true)])
            ->when(! $verTodas, fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('name')
            ->get();
    }

    public function totalActivas(): int
    {
        return MedicalCenter::where('activo', true)->count();
    }

    public function encontrar(int $id): MedicalCenter
    {
        return MedicalCenter::findOrFail($id);
    }

    /**
     * Las ciudades con su estado y su país, ya ordenadas para el `<select>`. Son ~30 filas sembradas (las
     * del catálogo de Venezuela), así que se sirven enteras.
     *
     * @return Collection<int,City>
     */
    public function ciudades(): Collection
    {
        return City::with('state.country')
            ->get()
            ->sortBy(fn (City $ciudad) => ($ciudad->state->name ?? '') . ' ' . $ciudad->name)
            ->values();
    }
}
