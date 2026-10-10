<?php

namespace App\Clinica\Agenda;

use App\Models\MotivoCita;
use App\Models\Office;
use Illuminate\Support\Collection;

/**
 * El catálogo de motivos (razones) de cita del médico, que usan el filtro de la agenda, el formulario
 * de la cita, la regla de la cita única (WEB-2.17) y la pantalla que marca los motivos.
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
     * **Todas** las filas del catálogo del médico, tal como están en la tabla: el ABM de motivos las
     * recorre fila por fila y las muestra todas, incluidas las repetidas por código —un mismo `codigo`
     * puede estar cargado una vez por sede y otra sin sede; `de()` se queda con una sola para el
     * `<select>`—, así que necesita el nombre de la sede para distinguirlas.
     *
     * @return Collection<int,MotivoCita>
     */
    public function delMedico(string $regMedico): Collection
    {
        return MotivoCita::with('office.medicalCenter')
            ->where('reg_medico', $regMedico)
            ->orderBy('codigo')
            ->orderBy('office_id')
            ->orderBy('id')
            ->get();
    }

    /** Un motivo del médico del contexto; el de otro médico no se toca (responde 404). */
    public function encontrar(string $regMedico, int $id): MotivoCita
    {
        return MotivoCita::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * ¿Ese motivo admite **varias citas pendientes** del mismo paciente? (WEB-2.17)
     *
     * Es la excepción a la regla de la cita única: con la marca puesta —masajistas, terapias— agendar no
     * pregunta qué hacer con la cita anterior. El motivo se resuelve sobre el **mismo catálogo** que arma
     * el `<select>` del formulario (`de()`: la fila de la sede gana sobre la que no tiene sede y el código
     * no se repite), para que la marca que se aplica sea la del motivo que el usuario vio elegido; el
     * formulario guarda ese código en `cola.tipo` (WEB-2.16), así que se busca por `codigo`.
     *
     * Sin motivo elegido no hay excepción: la regla común se aplica como siempre.
     */
    public function admiteVariasCitas(string $regMedico, ?string $codigo, ?Office $sede = null): bool
    {
        if ($codigo === null || $codigo === '') {
            return false;
        }

        return (bool) $this->de($regMedico, $sede)->firstWhere('codigo', $codigo)?->admite_varias_citas_pendientes;
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
