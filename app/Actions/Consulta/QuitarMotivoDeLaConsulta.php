<?php

namespace App\Actions\Consulta;

use App\Models\MotivoConsultaPaciente;
use App\Models\SyncChange;

/**
 * **Quitar un motivo de una consulta** (`motivo_consulta_paciente`, ROADMAP.md Paso 18.B2).
 *
 * Es la otra mitad de `AgregarMotivoALaConsulta` y la misma regla que el legado: quitar el motivo **no
 * toca el catálogo** ("eliminar un motivo de una consulta no lo elimina del catálogo maestro: solo
 * desasocia", wiki `04-historia-clinica.md`). Estaba adentro del sync del móvil
 * (`CreacionesClinicas::quitarMotivoDeConsulta`) y se extrajo acá al hacer **WEB-4.2**, para que la
 * consulta de la web suelte motivos con la misma regla y el mismo rastro (PLAN-WEB.md, R3).
 *
 * **Deja rastro en `sync_changes`** (`deleted`): es lo que hace que los demás teléfonos —y el
 * escritorio— se enteren del borrado. Idempotente: si la fila ya no está, no hace nada y no duplica el
 * aviso (`MotivosConsultaTest::test_quitar_un_motivo_...`).
 */
final class QuitarMotivoDeLaConsulta
{
    /**
     * @param  int  $id  la fila de `motivo_consulta_paciente` (el id que el teléfono y la web comparten)
     * @param  array<int,string>|null  $registrosMedicos  los registros del tenant que puede tocar: la fila
     *                                                    de otro médico no se borra. `null` = no restringir
     *                                                    (la web ya resolvió la fila contra su contexto)
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     * @return bool  si se borró ahora (`false` = ya no estaba)
     */
    public function ejecutar(int $id, ?array $registrosMedicos = null, string $origen = 'mobile'): bool
    {
        $consulta = MotivoConsultaPaciente::query();

        if ($registrosMedicos !== null) {
            $consulta->whereIn('reg_medico', $registrosMedicos);
        }

        $fila = $consulta->find($id);

        if (! $fila) {
            return false;
        }

        $regMedico = $fila->reg_medico;
        $fila->delete();

        SyncChange::create([
            'reg_medico' => $regMedico,
            'table_name' => 'motivo_consulta_paciente',
            'record_id' => $id,
            'operation' => 'deleted',
            'occurred_at' => now(),
            'source' => $origen,
        ]);

        return true;
    }
}
