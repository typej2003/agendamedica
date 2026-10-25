<?php

namespace App\Reservas;

use App\Models\Medico;
use App\Models\Specialty;
use Illuminate\Support\Collection;

/**
 * El catálogo público de médicos con **agenda publicada**: los únicos que el portal puede ofrecer.
 *
 * Es una decisión de producto, no un detalle: un médico sin consultorio activo o sin bloques cargados no
 * se muestra, porque el paciente no tendría nada que elegir. La fuente de "tiene agenda" es
 * `DisponibilidadPublica`, que a su vez usa la regla de la agenda real; acá solo se filtra y se ordena.
 */
final class CatalogoDeMedicos
{
    /** Memo del filtro de especialidades: se arma de los médicos con agenda y `medicos()` no es gratis. */
    private ?Collection $especialidadesConAgenda = null;

    public function __construct(private DisponibilidadPublica $disponibilidad)
    {
    }

    /**
     * @return Collection<int,Medico>
     */
    public function medicos(?string $especialidad = null, string $buscar = ''): Collection
    {
        $consulta = Medico::query()
            ->where('is_active', true)
            ->whereHas('specialties', fn ($q) => $q->where('activo', true))
            ->with(['specialties' => fn ($q) => $q->where('activo', true)->orderBy('name')]);

        if ($especialidad !== null && $especialidad !== '') {
            $consulta->whereHas('specialties', fn ($q) => $q->where('slug', $especialidad));
        }

        if ($buscar !== '') {
            $texto = '%' . $buscar . '%';
            $consulta->where(function ($q) use ($texto) {
                $q->where('name', 'like', $texto)
                    ->orWhere('lastname', 'like', $texto);
            });
        }

        return $consulta
            ->orderBy('lastname')
            ->orderBy('name')
            ->get()
            ->filter(fn (Medico $medico) => $this->disponibilidad->tieneAgenda($medico))
            ->values();
    }

    /**
     * Las especialidades del filtro: **sólo las que llevan a algún resultado**, o sea las de los médicos que hoy
     * se muestran en el catálogo (activos y con agenda publicada). Antes se ofrecía toda especialidad que tuviera
     * algún médico activo, aunque ninguno tuviera horarios, y el filtro llevaba a una lista vacía (2026-10-24).
     *
     * @return Collection<int,Specialty>
     */
    public function especialidades(): Collection
    {
        return $this->especialidadesConAgenda ??= $this->medicos()
            ->flatMap(fn (Medico $medico) => $medico->specialties)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /** Las sedes con horario del médico, para mostrarlas en su ficha pública. */
    public function sedes(Medico $medico): Collection
    {
        return $this->disponibilidad->sedes($medico)
            ->map(fn ($office) => $office->medicalCenter)
            ->filter()
            ->unique('id')
            ->values();
    }
}
