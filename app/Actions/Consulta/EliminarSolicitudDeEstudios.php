<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\RadiologiaObs;
use App\Models\RadiologiaPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar la solicitud de estudios de una consulta** (WEB-5.4): todos sus estudios (`radiologia_paciente`) y su observación
 * (`radiologia_obs`). Siempre se puede, sin límite de tiempo.
 *
 * Borra las filas de la consulta y deja su `deleted` en `sync_changes` (`source = web`).
 */
final class EliminarSolicitudDeEstudios
{
    use EscribeConRastro;

    /**
     * @return int cuántas filas se quitaron entre estudios y observación (`0` = la consulta no tenía solicitud)
     */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cuando, $origen) {
            $estudios = RadiologiaPaciente::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();
            $observaciones = RadiologiaObs::where('reg_medico', $consulta->reg_medico)
                ->where('numhistoria', (int) $consulta->numhistoria)
                ->where('numconsulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($estudios as $fila) {
                $this->borrarFila($fila, 'radiologia_paciente', $cuando, $origen);
            }

            foreach ($observaciones as $fila) {
                $this->borrarFila($fila, 'radiologia_obs', $cuando, $origen);
            }

            return $estudios->count() + $observaciones->count();
        });
    }
}
