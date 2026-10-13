<?php

namespace App\Actions\Consultorio;

use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de un **bloque de horario** de un consultorio (`office_schedules`) — WEB-2.8b.3.
 *
 * El bloque es lo que define la **jornada** de la agenda: `ArmadorDeAgenda`/`Jornada` agrupan las
 * citas por fecha + sede + bloque, el cupo se mide contra el bloque (no contra el día) y la hora con
 * la que nace una cita por orden de llegada es la apertura del bloque. Un consultorio sin bloques
 * sigue funcionando (la cita cae en "mañana" o "tarde" según el mediodía), pero la jornada deja de
 * tener tope y horario.
 *
 * Dos reglas sobre el solape, que es lo único que no es obvio:
 *
 *  - **Dentro del mismo consultorio se rechaza**: dos bloques que se pisan el mismo día dejan la
 *    jornada ambigua —una cita cae en los dos y el cupo se contaría dos veces—, así que no es un
 *    caso a decidir por el usuario.
 *  - **Con otro consultorio del mismo médico se avisa y se guarda** (decisión de Alexander,
 *    2026-10-09, TODO-WEB.md WEB-2.8b.3): el médico puede tener motivos para pisarse (atiende en dos
 *    lugares, o uno es de respaldo); lo que no puede pasar es que la web lo deje hacerlo en silencio.
 *    El aviso se devuelve como texto para que la pantalla lo muestre.
 */
final class GuardarBloqueHorario
{
    /**
     * @param  array{dia_semana: int, hora_inicio: string, hora_fin: string, cupo?: ?int}  $datos
     * @return ?string  el aviso de solape con otro consultorio del médico (`null` si no hay)
     *
     * @throws ValidationException si el bloque se pisa con otro del mismo consultorio y día
     */
    public function ejecutar(
        array $datos,
        Office $consultorio,
        string $regMedico,
        ?OfficeSchedule $bloque = null
    ): ?string {
        $bloque ??= new OfficeSchedule();

        $bloque->fill([
            'office_id'   => $consultorio->id,
            'dia_semana'  => (int) $datos['dia_semana'],
            'hora_inicio' => $datos['hora_inicio'],
            'hora_fin'    => $datos['hora_fin'],
            // Sin cupo queda nulo, no en un tope inventado: el mismo criterio que la duración del
            // consultorio y que los datos reales (nadie configuró cupo).
            'cupo'        => $datos['cupo'] ?? null,
        ]);

        // De quién es el bloque sale del consultorio (que ya es del médico del contexto), no del
        // formulario. Si la fila es vieja y no tiene registro, se usa el del contexto.
        $bloque->reg_medico = $consultorio->reg_medico ?: $regMedico;

        $choque = $this->choqueEnElMismoConsultorio($bloque, $consultorio);
        if ($choque !== null) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'Ese horario se pisa con el bloque ' . $choque->rango()
                    . ' de este mismo consultorio. Un bloque no puede repetirse ni superponerse: '
                    . 'la jornada de ese día quedaría partida en dos.',
            ]);
        }

        $bloque->save();

        return $this->avisoDeOtroConsultorio($bloque, $consultorio, $regMedico);
    }

    /** El bloque del mismo consultorio y día que se pisa con el que se está guardando. */
    private function choqueEnElMismoConsultorio(OfficeSchedule $bloque, Office $consultorio): ?OfficeSchedule
    {
        return OfficeSchedule::where('office_id', $consultorio->id)
            ->where('dia_semana', $bloque->dia_semana)
            ->when($bloque->exists, fn ($consulta) => $consulta->where('id', '<>', $bloque->id))
            ->get()
            ->first(fn (OfficeSchedule $otro) => $bloque->seCruzaCon($otro));
    }

    /**
     * El aviso: otro consultorio **del mismo médico** con un bloque que se pisa ese día. No impide
     * guardar; se devuelve el texto para que la pantalla lo muestre.
     */
    private function avisoDeOtroConsultorio(
        OfficeSchedule $bloque,
        Office $consultorio,
        string $regMedico
    ): ?string {
        $otro = OfficeSchedule::where('office_id', '<>', $consultorio->id)
            ->where('dia_semana', $bloque->dia_semana)
            ->whereHas('office', function ($consulta) use ($consultorio, $regMedico) {
                // El mismo criterio de "los consultorios de este médico" que usa la pantalla
                // (`Office::scopeDelMedico`) y el scope de vigentes.
                $consulta->delMedico($consultorio->reg_medico ?: $regMedico, $consultorio->medico_id);
            })
            ->with('office.medicalCenter')
            ->get()
            ->first(fn (OfficeSchedule $candidato) => $bloque->seCruzaCon($candidato));

        if ($otro === null) {
            return null;
        }

        $sede = $otro->office->medicalCenter->name ?? 'otra sede';
        $numero = $otro->office->office_number ?? '';
        $etiqueta = $numero !== '' ? $sede . ' · ' . $numero : $sede;

        return 'Ojo: los ' . mb_strtolower($bloque->nombreDelDia()) . ' ' . $bloque->rango()
            . ' te cruzan con tu consultorio de ' . $etiqueta . ' (' . $otro->rango()
            . '). Queda guardado igual, pero vas a estar en dos lugares a la vez.';
    }
}
