<?php

namespace App\Reservas;

use App\Clinica\CitaOnline\ConfiguracionDeCitaOnline;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\Specialty;
use Illuminate\Support\Collection;

/**
 * El catálogo público de médicos con **agenda publicada**: los únicos que el portal puede ofrecer.
 *
 * Es una decisión de producto, no un detalle: un médico sin consultorio activo o sin bloques cargados no
 * se muestra, porque el paciente no tendría nada que elegir. La fuente de "tiene agenda" es
 * `DisponibilidadPublica`, que a su vez usa la regla de la agenda real; acá solo se filtra y se ordena.
 *
 * Desde el 2026-10-30 la **publicación es una decisión del médico** (`evolucion.publicar_agenda`): con
 * `false` no aparece aunque tenga horarios. `null` —las filas que ya existían y las que sube el
 * escritorio— cuenta como publicado, así que el portal no se vacía de golpe. La regla del valor por
 * defecto vive en `ConfiguracionDeCitaOnline`.
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
            ->with([
                'registro',
                'specialties' => fn ($q) => $q->where('activo', true)->orderBy('name'),
            ]);

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

        $medicos = $consulta
            ->orderBy('lastname')
            ->orderBy('name')
            ->get();

        $configuraciones = $this->configuracionesDe($medicos);

        return $medicos
            ->filter(fn (Medico $medico) => $this->disponibilidad->tieneAgenda($medico)
                && $configuraciones[(int) $medico->id]->publicaAgenda())
            ->values();
    }

    /**
     * La configuración de la cita online de cada médico de la lista, en **una sola consulta** a `evolucion`
     * (el catálogo puede tener cientos de médicos). La fila se busca por el registro con el que se guarda
     * la configuración del médico (`registroDelFormato()`), que es el mismo que usa la pantalla de
     * Configuración.
     *
     * @param  Collection<int,Medico>  $medicos
     * @return array<int,ConfiguracionDeCitaOnline>
     */
    private function configuracionesDe(Collection $medicos): array
    {
        $registros = $medicos
            ->map(fn (Medico $medico) => $medico->registroDelFormato())
            ->filter()
            ->unique()
            ->values();

        $filas = $registros->isEmpty()
            ? collect()
            : Evolucion::whereIn('reg_medico', $registros->all())->get()->keyBy('reg_medico');

        $configuraciones = [];

        foreach ($medicos as $medico) {
            $registro = $medico->registroDelFormato();
            $configuraciones[(int) $medico->id] = ConfiguracionDeCitaOnline::de(
                $registro === null ? null : $filas->get($registro),
            );
        }

        return $configuraciones;
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
