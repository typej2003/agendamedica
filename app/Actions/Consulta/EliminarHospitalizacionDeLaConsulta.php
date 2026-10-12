<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\Hospitalizacion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar la orden de hospitalización de una consulta** (WEB-5.3b). Siempre se puede, sin límite de tiempo.
 *
 * Borra la fila de `hospitalizacion` de la consulta (y deja su `deleted` en `sync_changes`). No deja una fila vacía: esas las crea el
 * escritorio al abrir la consulta, no la web.
 */
final class EliminarHospitalizacionDeLaConsulta
{
    use EscribeConRastro;

    /**
     * @return int cuántas filas se quitaron (`0` = la consulta no tenía orden)
     */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cuando, $origen) {
            $filas = Hospitalizacion::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($filas as $fila) {
                $this->borrarFila($fila, 'hospitalizacion', $cuando, $origen);
            }

            return $filas->count();
        });
    }
}
