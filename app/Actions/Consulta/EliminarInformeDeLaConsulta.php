<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\Informe;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar el informe médico de una consulta** (WEB-5.3a). Siempre se puede, sin límite de tiempo.
 *
 * Borra la fila de `informe` de la consulta (y deja su `deleted` en `sync_changes`, que es lo que el móvil usa para
 * soltarla). No deja la plantilla `.`: esa la crea el escritorio al abrir la consulta, no la web.
 */
final class EliminarInformeDeLaConsulta
{
    use EscribeConRastro;

    /**
     * @return int cuántas filas se quitaron (`0` = la consulta no tenía informe)
     */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cuando, $origen) {
            $filas = Informe::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($filas as $fila) {
                $this->borrarFila($fila, 'informe', $cuando, $origen);
            }

            return $filas->count();
        });
    }
}
