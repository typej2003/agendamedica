<?php

namespace App\Clinica\Agenda;

use App\Models\MotivoCita;
use App\Models\Office;
use Illuminate\Support\Collection;

/**
 * El catálogo de motivos (razones) de cita del médico, que usan el filtro de la agenda y el formulario
 * de la cita.
 */
class MotivosDeCita
{
    /**
     * Los motivos de cita que se pueden elegir en esa sede: los suyos primero y los que no tienen
     * sede (los que sube el escritorio, que no conoce el concepto de sede) como respaldo. Nunca se
     * repite un código: el `<select>` no admite dos opciones con el mismo valor.
     *
     * @return Collection<int,MotivoCita>
     */
    public function de(string $regMedico, ?Office $sede = null): Collection
    {
        return MotivoCita::where('reg_medico', $regMedico)
            ->get()
            ->sortByDesc(fn (MotivoCita $motivo) => $motivo->office_id === $sede?->id)
            ->unique('codigo')
            ->sortBy('tipo_atencion')
            ->values();
    }

    /**
     * La razón pedida en el filtro (WEB-2.14): el `codigo` de `motivo_cita` del médico, o `null` si
     * no se filtró o si el código no está en su catálogo.
     *
     * La comprobación contra el catálogo es lo que hace que un valor inventado en la URL se comporte
     * como "Todas las razones" (`ddlb_1` del escritorio: `TODOS` deja el filtro vacío y no filtra) en
     * vez de dejar la agenda en blanco, que no tendría forma de explicarse. El valor viaja como `value`
     * de cada `<option>`, así que es también lo que se compara contra `cola.tipo` y `cola.motivo`.
     *
     * Un motivo de otra sede del mismo médico también sirve: el catálogo es del médico, y `cola` no
     * dice en qué consultorio se eligió. Lo que no se acepta es un código que no existe.
     *
     * @param  Collection<int,MotivoCita>  $razones  el catálogo del médico del contexto
     */
    public function vigente(?string $pedida, Collection $razones): ?string
    {
        if ($pedida === null || $pedida === '') {
            return null;
        }

        return $razones->contains(fn (MotivoCita $motivo) => (string) $motivo->codigo === $pedida)
            ? $pedida
            : null;
    }
}
