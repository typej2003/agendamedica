<?php

namespace App\Clinica\Consulta;

use App\Models\Cola;
use Carbon\CarbonInterface;

/**
 * **La cita de una consulta** (WEB-4.8): cuál es la cita de la agenda que corresponde a la atención que
 * se está mirando, para poder terminarla.
 *
 * El vínculo no es una columna: **`cola` no tiene `nroconsulta`** (wiki `07-modelo-de-datos.md`), así que
 * la cita y la consulta se relacionan por el **número de historia** (el del pivote) y por la **fecha**,
 * que es exactamente lo que hace el resto del proyecto para resolver "la consulta del día"
 * (`App\Actions\Consulta\AbrirConsulta::delDia`, en el sentido contrario).
 *
 * Se busca de dos formas, en ese orden, porque en la base conviven las dos:
 *
 *  1. **Por `cola.numhistoria`**, que es el camino normal: la cita ya lleva el número.
 *  2. **Por `cola.paciente_sinhistoria_id`**, para la cita que nació anclada al vínculo del paciente y
 *     **sin** número —el alta rápida del móvil cuando el paciente todavía no estaba en el servidor
 *     (`SyncAppDataController::crearCola`)— y cuyo vínculo ya tiene la historia. Sin este segundo intento
 *     esa cita quedaría sin poder cerrarse desde la web aunque su paciente ya tenga número. El enlace lo
 *     hace después `App\Actions\Agenda\AtenderCita`, que resuelve el número del pivote; acá sólo se
 *     necesita **encontrar** la fila.
 *
 * Se acota además por el `reg_medico` de la consulta y, cuando la cita dice en qué sede es
 * (`cola.medical_center_id`, columna de AppDDR y nullable), por esa sede: un paciente puede tener dos
 * citas el mismo día en dos consultorios y la que se termina es la del lugar donde se lo atendió. Si la
 * cita no tiene sede —las del legado y las que no la eligieron— se acepta igual.
 *
 * Es **sólo lectura** y no sabe de HTTP: la usan el shell (para saber si ofrece "Terminar la consulta")
 * y la Action que la termina (`App\Actions\Consulta\CerrarConsulta`).
 */
final class CitaDeLaConsulta
{
    /**
     * La cita de la agenda de una consulta, o `null` si no hay ninguna.
     *
     * @param  int|null  $pacienteId  `pacientes.id` del paciente de la consulta, para el segundo intento
     * @param  int|null  $sede  `medical_center_id` de la consulta, si su historia dice dónde fue
     */
    public function de(
        int $numhistoria,
        CarbonInterface $fecha,
        string $regMedico,
        ?int $sede = null,
        ?int $pacienteId = null,
    ): ?CitaEncontrada {
        $cita = $this->buscar($regMedico, $fecha, $sede)
            ->where('numhistoria', $numhistoria)
            ->first();

        if ($cita === null && $pacienteId !== null) {
            $cita = $this->buscar($regMedico, $fecha, $sede)
                ->whereNull('numhistoria')
                ->where('paciente_sinhistoria_id', $pacienteId)
                ->first();
        }

        if ($cita === null) {
            return null;
        }

        return new CitaEncontrada((int) $cita->id, (bool) $cita->movida_escritorio);
    }

    /**
     * La consulta base: la de ese día, ese registro y —si se sabe— esa sede.
     *
     * **Las citas movidas por el escritorio quedan al final**: son la fila vieja de una cita que ya está en
     * otra fecha, así que si el paciente tiene además una cita viva ese día, la que se termina es la viva.
     * Si la única que hay es la movida, se devuelve igual y `CitaEncontrada::terminable` se encarga de que
     * no se ofrezca la acción.
     *
     * Si hay más de una viva, la del orden más alto es la última que el mostrador puso en la cola —el mismo
     * criterio que el resto de la agenda (`ArmadorDeAgenda`)—.
     */
    private function buscar(string $regMedico, CarbonInterface $fecha, ?int $sede)
    {
        return Cola::where('reg_medico', $regMedico)
            ->whereDate('fecha', $fecha->toDateString())
            ->when($sede !== null, function ($consulta) use ($sede) {
                // Una cita sin sede entra: los datos del legado no la tienen y no por eso deja de ser la
                // cita del paciente ese día.
                $consulta->where(function ($filtro) use ($sede) {
                    $filtro->whereNull('medical_center_id')->orWhere('medical_center_id', $sede);
                });
            })
            ->orderByRaw('CASE WHEN movida_escritorio = 1 THEN 1 ELSE 0 END')
            ->orderByDesc('numorden')
            ->orderByDesc('id');
    }
}
