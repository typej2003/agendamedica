<?php

namespace App\Clinica\Guia;

use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;

/**
 * **La guía de puesta en marcha del médico**: el *siguiente* dato esencial que le falta, de uno en uno y empezando
 * por el menos dependiente (sede → consultorio → horario). Es la misma exigencia que `CatalogoDeMedicos` le hace al
 * portal de reservas (consultorio activo en sede activa, con al menos un bloque); acá se redacta como pasos.
 *
 * Sin HTTP: devuelve `null` cuando no falta nada.
 */
final class PasosEsenciales
{
    public function siguiente(Medico $medico): ?PasoEsencial
    {
        $reg = $medico->regMedicoPrincipal();

        if ($reg === null) {
            return null;
        }

        if (! MedicalCenter::activos()->exists()) {
            return new PasoEsencial(
                'sede', 'clinica.sedes', [],
                'Todavía no hay ninguna sede registrada.',
                'Registra el lugar donde atiendes',
                'Todavía no hay una sede registrada. ¿Quieres registrar el lugar donde atiendes ahora?',
            );
        }

        $consultorios = Office::with('schedules')
            ->vigentesDe($reg, $medico->id)
            ->orderBy('id')
            ->get();

        if ($consultorios->isEmpty()) {
            return new PasoEsencial(
                'consultorio', 'clinica.consultorios', [],
                'No has agregado ningún consultorio.',
                'Agrega tu primer consultorio',
                'No has agregado ningún consultorio. ¿Quieres agregarlo ahora?',
            );
        }

        if ($consultorios->contains(fn (Office $o) => $o->schedules->isNotEmpty())) {
            return null;
        }

        $sinHorario = $consultorios->first();

        return new PasoEsencial(
            'horario', 'clinica.consultorios.horarios', [$sinHorario->id],
            'No has configurado tu horario de trabajo en el consultorio ' . $sinHorario->office_number . '.',
            'Configura tu horario',
            'No has configurado tu horario de trabajo en ese consultorio. ¿Quieres configurarlo ahora?',
        );
    }
}
