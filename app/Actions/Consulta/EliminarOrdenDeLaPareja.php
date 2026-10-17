<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\ExamenPareja;
use App\Models\ExamenParejaDatos;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar la orden de laboratorio de la pareja** (WEB-11.21): todos sus exámenes (`examen_pareja`) y sus datos (`examen_pareja_datos`).
 * Deja su `deleted` en `sync_changes` (`source = web`). No toca la orden de la paciente.
 */
final class EliminarOrdenDeLaPareja
{
    use EscribeConRastro;

    /** @return int cuántas filas se quitaron (`0` = la consulta no tenía orden de la pareja) */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cuando, $origen) {
            $examenes = ExamenPareja::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();
            $datos = ExamenParejaDatos::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($examenes as $fila) {
                $this->borrarFila($fila, 'examen_pareja', $cuando, $origen);
            }

            foreach ($datos as $fila) {
                $this->borrarFila($fila, 'examen_pareja_datos', $cuando, $origen);
            }

            return $examenes->count() + $datos->count();
        });
    }
}
