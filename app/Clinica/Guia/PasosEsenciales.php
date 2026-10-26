<?php

namespace App\Clinica\Guia;

use App\Clinica\Consultorio\SedesDeLaClinica;
use App\Models\Medico;
use App\Models\Office;

/**
 * **La guía de puesta en marcha del médico**: el *siguiente* dato esencial que le falta, de uno en uno y empezando
 * por el menos dependiente (sede → consultorio → horario). Es la misma exigencia que `CatalogoDeMedicos` le hace al
 * portal de reservas (consultorio activo en sede activa, con al menos un bloque); acá se redacta como pasos.
 *
 * **El paso de la sede mira SUS sedes**, no el catálogo (2026-10-26: cada médico tiene las suyas). Antes era
 * `MedicalCenter::activos()->exists()` global: en cuanto cualquiera registraba una sede, el médico sin sedes se
 * saltaba el paso y caía en «no has agregado ningún consultorio», donde no había dónde colgarlo.
 *
 * Sin HTTP: devuelve `null` cuando no falta nada. La sede y el consultorio se cargan en la misma pantalla («Sedes y
 * consultorios», 2026-10-20): el enlace abre directamente su panel lateral.
 */
final class PasosEsenciales
{
    public function __construct(private SedesDeLaClinica $sedes)
    {
    }

    public function siguiente(Medico $medico): ?PasoEsencial
    {
        $reg = $medico->regMedicoPrincipal();

        if ($reg === null) {
            return null;
        }

        // Las suyas: dueño, asignadas y sedes donde ya tiene un consultorio (ver `SedesDeLaClinica`).
        $susSedes = $this->sedes->listar(['reg_medico' => $reg, 'medico' => $medico], true);

        if ($susSedes->isEmpty()) {
            return new PasoEsencial(
                'sede', 'clinica.sedes', ['panel' => 'sede'],
                'Todavía no tienes ninguna sede registrada.',
                'Registra el lugar donde atiendes',
                'Todavía no tienes una sede registrada. ¿Quieres registrar el lugar donde atiendes ahora?',
            );
        }

        $consultorios = Office::with('schedules')
            ->vigentesDe($reg, $medico->id)
            ->orderBy('id')
            ->get();

        if ($consultorios->isEmpty()) {
            return new PasoEsencial(
                'consultorio', 'clinica.sedes', ['panel' => 'consultorio'],
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
