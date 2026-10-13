<?php

namespace App\Clinica\Agenda;

use App\Models\MotivoCita;
use App\Models\Office;
use Illuminate\Support\Collection;

/**
 * El catálogo de motivos (razones) de cita del médico **tal como lo usa la agenda**: el filtro, el `<select>` del formulario
 * de la cita, el precio del monto vacío y la regla de la cita única (WEB-2.17). El ABM del catálogo (alta, edición y quitar,
 * WEB-8.1c) es otra lectura: `App\Clinica\Catalogos\MotivosDeCitaDelCatalogo`.
 */
class MotivosDeCita
{
    /**
     * Los motivos de cita que se pueden elegir en esa sede (WEB-8.1c): **los suyos y los que no tienen sede**
     * (los que sube el escritorio, que no conoce el concepto de sede, y los que el médico crea para todas).
     * Los de **otra** sede no se ofrecen: un motivo de una sede puede no existir en las demás (equipo que sólo hay en
     * un consultorio) y su precio puede ser otro. Nunca se repite un código —el `<select>` no admite dos opciones con
     * el mismo valor—: si la sede y «todas» traen el mismo código, gana el de la sede.
     *
     * Sin sede elegida (`null`) no hay a qué acotar: se devuelve el catálogo del médico con un solo motivo por código
     * (el filtro de la agenda lo usa así). Esta lista es también **la que resuelve** el precio y la marca del código que
     * llega del formulario (`AgendamientoDeCitas`), así que el formulario y el servidor miran el mismo catálogo.
     *
     * @return Collection<int,MotivoCita>
     */
    public function de(string $regMedico, ?Office $sede = null): Collection
    {
        return MotivoCita::where('reg_medico', $regMedico)
            ->get()
            ->when($sede !== null, fn (Collection $motivos) => $motivos->filter(
                fn (MotivoCita $motivo) => $motivo->office_id === null || (int) $motivo->office_id === (int) $sede->id
            ))
            ->sortByDesc(fn (MotivoCita $motivo) => $motivo->office_id === $sede?->id)
            ->unique('codigo')
            ->sortBy('tipo_atencion')
            ->values();
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
