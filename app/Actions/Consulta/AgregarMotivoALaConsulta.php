<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\MotivoConsulta;
use App\Models\MotivoConsultaPaciente;
use App\Models\SyncChange;

/**
 * **Agregar un motivo del catálogo a una consulta** (`motivo_consulta_paciente`, ROADMAP.md Paso 18.B2).
 *
 * La regla —que hoy usan el sync del móvil y, desde **WEB-4.2**, la consulta de la web— es una sola:
 * la fila cuelga de la consulta (`nrohistoria` + `nroconsulta` + el `reg_medico` de la consulta) y
 * apunta al **código** de un motivo que ya está en el catálogo del médico. **Agregar dos veces el mismo
 * motivo a la misma consulta devuelve la fila que ya estaba** en vez de duplicarla, que es lo que el
 * legado hace ("eliminar un motivo de una consulta no lo elimina del catálogo maestro: solo desasocia",
 * wiki `04-historia-clinica.md`).
 *
 * **Deja rastro en `sync_changes`** (`created`, con `source` según quién escribe): `motivo_consulta_paciente`
 * no es una tabla derivada, es una tabla legada que el móvil y el escritorio sincronizan, así que lo que
 * agrega la web tiene que registrarse igual que lo que agrega la app (PLAN-WEB.md, R3). El sync del móvil
 * **no** lo registra acá: lo anota él con el id temporal del lote (`CreacionesClinicas::anotar`), que es
 * el dato que sólo el sync tiene.
 */
final class AgregarMotivoALaConsulta
{
    /**
     * @param  Consulta  $consulta  la consulta a la que se agrega (ya resuelta y del médico del contexto)
     * @param  MotivoConsulta  $motivo  un motivo del catálogo de ese médico
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     * @return array{0: MotivoConsultaPaciente, 1: bool} la fila y si se creó ahora (`false` = ya estaba)
     */
    public function ejecutar(Consulta $consulta, MotivoConsulta $motivo, string $origen = 'mobile'): array
    {
        $codigo = (string) $motivo->codemotivo;

        $fila = MotivoConsultaPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->where('codemotivo', $codigo)
            ->first();

        if ($fila) {
            return [$fila, false];
        }

        $fila = MotivoConsultaPaciente::create([
            'reg_medico' => $consulta->reg_medico,
            'codemotivo' => $codigo,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
        ]);

        SyncChange::create([
            'reg_medico' => $fila->reg_medico,
            'table_name' => 'motivo_consulta_paciente',
            'record_id' => $fila->id,
            'operation' => 'created',
            'occurred_at' => now(),
            'source' => $origen,
        ]);

        return [$fila, true];
    }
}
