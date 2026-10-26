<?php

namespace App\Services\Admin;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lectura del panel **«Sedes del médico»** (`/admin/medicos/{medico}/sedes` y sus niveles): dónde atiende un
 * médico, con qué consultorios y con qué horarios, visto por un administrador que lo ayuda a configurarse.
 *
 * **De quién es cada cosa** (lo único delicado de la pantalla):
 *
 *  - La **sede** (`medical_centers`) tiene dueño desde el 2026-10-26 (`medico_id`), pero «las sedes de un
 *    médico» en esta pantalla siguen siendo las sedes donde **su tenant tiene consultorios**: el panel administra
 *    consultorios, no la ficha de la sede. Una sede se le puede **asignar** a otro médico (Root puede hacerlo)
 *    aunque sea de uno; por eso la lectura de acá no se filtra por dueño.
 *  - El **consultorio** (`offices`) es del tenant `reg_medico` (el del médico, o el del centro si la ficha es un
 *    centro). Acá el criterio es **estricto**: `reg_medico` del médico, y por `medico_id` sólo las filas viejas
 *    que todavía no tienen registro. **No** se usa `Office::scopeDelMedico` tal cual porque su `orWhere
 *    medico_id` también trae los consultorios **de un centro** que creó ese doctor (reg_medico del centro y
 *    medico_id del doctor): editarlos desde la ficha del doctor los habría mudado a su tenant, porque
 *    `GuardarConsultorio` reescribe el `reg_medico`.
 *  - El **bloque de horario** (`office_schedules`) cuelga del consultorio.
 */
class SedesDelMedico
{
    /** El tenant del médico (su `reg_medico`), o `null` si la ficha no tiene: sin él no hay de quién leer. */
    public function tenant(Medico $medico): ?string
    {
        $registro = trim((string) $medico->reg_medico);

        return $registro === '' ? null : $registro;
    }

    /** Los consultorios del tenant del médico (activos o no). */
    public function consultorios(Medico $medico): Builder
    {
        $registro = (string) $this->tenant($medico);

        return Office::query()->where(function ($consulta) use ($registro, $medico) {
            $consulta->where('reg_medico', $registro)
                ->orWhere(function ($viejas) use ($medico) {
                    $viejas->where('medico_id', $medico->id)
                        ->where(fn ($sin) => $sin->whereNull('reg_medico')->orWhere('reg_medico', ''));
                });
        });
    }

    /**
     * Las sedes donde el médico tiene consultorios, con cuántos (activos y en total). Por defecto sólo las que
     * tienen alguno **activo**: una sede donde todos sus consultorios están dados de baja es historia.
     *
     * @return Collection<int,MedicalCenter>
     */
    public function sedes(Medico $medico, bool $conInactivas): Collection
    {
        $ids = $this->consultorios($medico)
            ->when(! $conInactivas, fn ($consulta) => $consulta->where('activo', true))
            ->pluck('medical_center_id')
            ->filter()
            ->unique()
            ->all();

        $propios = fn ($consulta) => $consulta->whereIn('offices.id', $this->consultorios($medico)->select('id'));

        return MedicalCenter::with('city.state')
            ->whereIn('id', $ids)
            ->withCount([
                'offices as consultorios_activos' => fn ($consulta) => $propios($consulta)->where('activo', true),
                'offices as consultorios_total'   => $propios,
            ])
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int,Office> los consultorios del médico en una sede, con sus bloques */
    public function consultoriosEn(Medico $medico, MedicalCenter $sede, bool $conInactivos): Collection
    {
        return $this->consultorios($medico)
            ->where('medical_center_id', $sede->id)
            ->when(! $conInactivos, fn ($consulta) => $consulta->where('activo', true))
            ->with(['schedules' => fn ($consulta) => $consulta->orderBy('dia_semana')->orderBy('hora_inicio')])
            ->orderBy('office_number')
            ->get();
    }

    /** Un consultorio del médico **en esa sede**; de otro médico o de otra sede, 404. */
    public function consultorio(Medico $medico, MedicalCenter $sede, int $id): Office
    {
        return $this->consultorios($medico)
            ->where('medical_center_id', $sede->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    /** Cuántos **otros** tenants tienen consultorio en la sede: editarla los afecta a ellos también. */
    public function otrosMedicosEnLaSede(Medico $medico, MedicalCenter $sede): int
    {
        return Office::where('medical_center_id', $sede->id)
            ->whereNotIn('id', $this->consultorios($medico)->select('id'))
            ->whereNotNull('reg_medico')
            ->where('reg_medico', '<>', '')
            ->distinct()
            ->count('reg_medico');
    }

    /** Las citas del médico en la sede desde hoy: es lo que se dice antes de quitarle la sede. */
    public function citasDesdeHoy(Medico $medico, MedicalCenter $sede): int
    {
        return Cola::where('reg_medico', (string) $this->tenant($medico))
            ->where('medical_center_id', $sede->id)
            ->whereDate('fecha', '>=', FechaDelConsultorio::ahora()->toDateString())
            ->count();
    }

    /** @return Collection<int,MedicalCenter> las sedes activas del catálogo, para «Agregar sede» */
    public function sedesDelCatalogo(): Collection
    {
        return MedicalCenter::activos()->with('city')->orderBy('name')->get();
    }
}
