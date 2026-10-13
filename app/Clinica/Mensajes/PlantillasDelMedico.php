<?php

namespace App\Clinica\Mensajes;

use App\Models\Evolucion;
use App\Models\Medico;

/**
 * **Lo que el médico tiene guardado como plantillas de mensaje** (WEB-8.4): `evolucion.plantilla_cita` y
 * `evolucion.plantilla_cumple` de su fila bajo el **registro principal** (la misma fila que escribe `GuardarMembreteDelMedico` y que
 * lee el móvil en `sync-app-data`), o nada si todavía no guardó ninguna.
 *
 * Sólo lectura. Mirar la pantalla no crea la fila de `evolucion`.
 */
final class PlantillasDelMedico
{
    /**
     * @return array{registro:string, evolucion:?Evolucion}
     */
    public function de(Medico $medico): array
    {
        $registro = (string) $medico->regMedicoPrincipal();

        return [
            'registro' => $registro,
            'evolucion' => $registro === '' ? null : Evolucion::where('reg_medico', $registro)->orderBy('id')->first(),
        ];
    }
}
