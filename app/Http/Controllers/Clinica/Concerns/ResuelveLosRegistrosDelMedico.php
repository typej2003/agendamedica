<?php

namespace App\Http\Controllers\Clinica\Concerns;

use App\Models\Medico;

/**
 * Los `reg_medico` de un médico, resueltos **igual que en el sync del móvil** (`SyncAppDataController`):
 * sus filas de `medico_registros` más su propio `medicos.reg_medico`, sin repetidos.
 *
 * Existe porque el conjunto importa más de lo que parece: `AsignarHistoria` calcula el correlativo del
 * número de historia sobre los `reg_medico` que recibe, así que si la web le pasara un conjunto distinto
 * al del teléfono, **el mismo paciente recibiría un número distinto según quién lo atienda**. La regla
 * era una sola y vivía adentro de `MostradorController` (WEB-2.11); al necesitarla la ficha de paciente
 * (WEB-3.3) se extrajo acá —entre los controladores de `/clinica`— en vez de copiarla, que es lo que la
 * guía de la web pide para las reglas que dos pantallas comparten.
 *
 * Desde la Fase 2 del cambio de `reg_medico` la lista incluye **los centros donde el doctor está
 * vinculado**, que es lo que hace que la lista de pacientes sea la global del doctor. La regla vive en
 * `Medico::registrosDeTrabajo()`, compartida con el contexto de trabajo y la agenda.
 */
trait ResuelveLosRegistrosDelMedico
{
    /**
     * @return array<int,string>
     */
    protected function registrosDelMedico(Medico $medico): array
    {
        return $medico->registrosDeTrabajo();
    }
}
