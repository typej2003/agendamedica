<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\DietaPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar la dieta / recomendaciones de una consulta** (WEB-5.3b). Siempre se puede, sin límite de tiempo: es el «Eliminar» del
 * escritorio (`pb_131`: `dw_89.deleterow` + `update()`).
 *
 * Borra la fila de `dieta_paciente` de la consulta y deja su `deleted` en `sync_changes`.
 */
final class EliminarDietaDeLaConsulta
{
    use EscribeConRastro;

    /**
     * @return int cuántas filas se quitaron (`0` = la consulta no tenía dieta)
     */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cuando, $origen) {
            $filas = DietaPaciente::where('reg_medico', $consulta->reg_medico)
                ->where('histroria', (int) $consulta->numhistoria)
                ->where('consulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($filas as $fila) {
                $this->borrarFila($fila, 'dieta_paciente', $cuando, $origen);
            }

            return $filas->count();
        });
    }
}
