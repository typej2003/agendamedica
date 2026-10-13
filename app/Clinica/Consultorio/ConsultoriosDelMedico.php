<?php

namespace App\Clinica\Consultorio;

use App\Models\MedicalCenter;
use App\Models\Office;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lectura de los **consultorios del médico del contexto** (`offices`): dónde atiende, con qué modalidad y
 * con qué duración. A diferencia de las sedes, el consultorio **es de un médico**: `reg_medico` y
 * `medico_id` salen siempre del contexto de trabajo, nunca del formulario.
 */
class ConsultoriosDelMedico
{
    /** Los consultorios del médico del contexto (`Office::scopeDelMedico`); el ajeno no existe para él. */
    public function delMedico(array $contexto): Builder
    {
        return Office::delMedico($contexto['reg_medico'], $contexto['medico']->id);
    }

    /**
     * Con su sede, su modalidad y sus bloques cargados. Los inactivos se esconden por defecto: la pantalla
     * es para trabajar, y lo dado de baja es historia.
     *
     * @return Collection<int,Office>
     */
    public function listar(array $contexto, bool $verInactivos): Collection
    {
        return $this->delMedico($contexto)
            ->with(['medicalCenter', 'schedules'])
            ->when(! $verInactivos, fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('medical_center_id')
            ->orderBy('office_number')
            ->get();
    }

    /** Un consultorio del médico; de otro da 404. */
    public function encontrar(array $contexto, int $id): Office
    {
        return $this->delMedico($contexto)->whereKey($id)->firstOrFail();
    }

    /** @return Collection<int,MedicalCenter> las sedes en las que se puede cargar un consultorio */
    public function sedesActivas(): Collection
    {
        return MedicalCenter::activos()->orderBy('name')->get();
    }
}
