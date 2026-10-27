<?php

namespace App\Clinica\Consultorio;

use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as ConsultaBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de las **sedes del médico del contexto** (`medical_centers`).
 *
 * Hasta el 2026-10-26 la sede era del **catálogo de la clínica** y todos veían todas. Alexander lo corrigió
 * («no debe ver las sedes de otros doctores, cada doctor tiene sus sedes»): la sede es de un médico
 * (`medical_centers.medico_id`) y «las suyas» son la **unión** de
 *
 *  1. las que **le pertenecen** (`medico_id`),
 *  2. las **asignadas** en `medico_medical_center` (la tabla puente que usa el panel de administración), y
 *  3. las sedes donde ya tiene un **consultorio** (`offices`), que es el criterio con el que la agenda sabe
 *     dónde atiende.
 *
 * Las tres, sin duplicar y en una sola consulta. La vía 3 es la que deja ver —pero **no** administrar: editar
 * y activar son sólo del dueño o de una asignada— una sede vieja sin dueño donde el médico ya cargó su
 * consultorio. **Sin contexto no hay sedes**: no hay médico del que sean.
 */
class SedesDeLaClinica
{
    /**
     * El listado con los dos datos que hacen falta para decidir: dónde está y **cuántos consultorios
     * activos** tiene (el consultorio cuelga de la sede). Una sede sin consultorios es una sede cargada y
     * sin usar, que es justo lo que hay que ver antes de desactivarla.
     *
     * @param  array<string,mixed>|null  $contexto  el `contexto_clinico` de la petición; `null` sin médico elegido
     * @return Collection<int,MedicalCenter>
     */
    public function listar(?array $contexto, bool $verTodas): Collection
    {
        return $this->consulta($contexto)
            ->when(! $verTodas, fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('name')
            ->get();
    }

    /** Las sedes **activas** del médico del contexto (el número del filtro «Activas (N)»). */
    public function totalActivas(?array $contexto): int
    {
        return $this->consulta($contexto)->where('activo', true)->count();
    }

    /**
     * Una sede que el médico **administra** (es su dueño o se la asignaron). Las que sólo ve porque tiene un
     * consultorio en ellas dan 404: su ficha es de otro médico.
     */
    public function encontrarAdministrable(?array $contexto, int $id): MedicalCenter
    {
        return $this->consulta($contexto)
            ->whereKey($id)
            ->where(fn (Builder $consulta) => $this->soloAdministrables($consulta, $contexto))
            ->firstOrFail();
    }

    /** La sede si es del médico; `null` si no, sin lanzar el 404 (para avisar con palabras). */
    public function buscar(?array $contexto, int $id): ?MedicalCenter
    {
        return $this->consulta($contexto)->whereKey($id)->first();
    }

    /**
     * Los ids de las sedes que puede **administrar** (dueño o asignada), que es lo que decide si la tarjeta
     * ofrece el lápiz y el tacho. Las que sólo ve por tener un consultorio no entran.
     *
     * @return array<int,int>
     */
    public function administrables(?array $contexto): array
    {
        if ($this->medicoDe($contexto) === null) {
            return [];
        }

        return $this->consulta($contexto)
            ->where(fn (Builder $consulta) => $this->soloAdministrables($consulta, $contexto))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Las ciudades con su estado y su país, ordenadas. Son ~500 filas sembradas: ya no alimentan un
     * select (el selector con búsqueda las pide a `BuscadorDeCiudades`); queda para quien necesite el catálogo entero.
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

    // ----------------------------------------------------------------------------------------- Por dentro

    /**
     * La consulta de las sedes del médico, con su ciudad y la cuenta de consultorios activos. Sin médico en el
     * contexto devuelve una consulta **vacía** (encadenable): la pantalla muestra el aviso, no un catálogo.
     */
    private function consulta(?array $contexto): Builder
    {
        $consulta = MedicalCenter::with('city')
            ->withCount(['offices as consultorios_activos' => fn ($oficinas) => $oficinas->where('activo', true)]);

        $medico = $this->medicoDe($contexto);

        if ($medico === null) {
            return $consulta->whereRaw('1 = 0');
        }

        $regMedico = (string) ($contexto['reg_medico'] ?? '');

        return $consulta->where(function (Builder $sedes) use ($medico, $regMedico) {
            $sedes->where('medico_id', $medico->id)
                ->orWhereIn('id', $this->asignadas($medico))
                // (c) Las sedes donde ya tiene consultorio, con el mismo criterio que la agenda.
                ->orWhereIn('id', Office::delMedico($regMedico, (int) $medico->id)->select('medical_center_id'));
        });
    }

    /** El grupo «es suya»: dueño o asignada. Es lo único que se puede editar o activar. */
    private function soloAdministrables(Builder $consulta, ?array $contexto): void
    {
        $medico = $this->medicoDe($contexto);

        if ($medico === null) {
            $consulta->whereRaw('1 = 0');

            return;
        }

        $consulta->where('medico_id', $medico->id)->orWhereIn('id', $this->asignadas($medico));
    }

    /** Las sedes **asignadas** en la tabla puente que usa el panel de administración. */
    private function asignadas(Medico $medico): ConsultaBase
    {
        return DB::table('medico_medical_center')
            ->select('medical_center_id')
            ->where('medico_id', $medico->id)
            ->whereNotNull('medical_center_id');
    }

    private function medicoDe(?array $contexto): ?Medico
    {
        $medico = $contexto['medico'] ?? null;

        return $medico instanceof Medico ? $medico : null;
    }
}
