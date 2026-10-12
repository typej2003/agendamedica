<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\ExamenObs;
use App\Models\ExamenPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar la orden de laboratorio de una consulta** (WEB-5.4): todos sus exámenes (`examen_paciente`) y su observación
 * (`examen_obs`). Siempre se puede, sin límite de tiempo.
 *
 * Borra las filas de la consulta y deja su `deleted` en `sync_changes` (`source = web`). Si algún examen tenía `resultado` se pierde con
 * él: la pantalla lo avisa en la confirmación (en la base real ninguna fila lo tiene).
 */
final class EliminarOrdenDeLaboratorio
{
    use EscribeConRastro;

    /**
     * @return int cuántas filas se quitaron entre exámenes y observación (`0` = la consulta no tenía orden)
     */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cuando, $origen) {
            $examenes = ExamenPaciente::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();
            $observaciones = ExamenObs::where('reg_medico', $consulta->reg_medico)
                ->where('numhistoria', (int) $consulta->numhistoria)
                ->where('numconsulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($examenes as $fila) {
                $this->borrarFila($fila, 'examen_paciente', $cuando, $origen);
            }

            foreach ($observaciones as $fila) {
                $this->borrarFila($fila, 'examen_obs', $cuando, $origen);
            }

            return $examenes->count() + $observaciones->count();
        });
    }
}
