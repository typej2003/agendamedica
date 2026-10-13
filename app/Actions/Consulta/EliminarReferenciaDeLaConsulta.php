<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\Referencia;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Quitar una referencia de la consulta** (WEB-5.3a): borra la fila `(nrohistoria, nroconsulta, ceduladoctor)` de
 * `referencia`, como el botón *Eliminar* del escritorio (`DELETE FROM referencia WHERE … ceduladoctor = :codigo_me`,
 * `w_consulta_principal.srw:18101`). El médico del catálogo **no se toca**. Deja su `deleted` en `sync_changes`.
 */
final class EliminarReferenciaDeLaConsulta
{
    use EscribeConRastro;

    /**
     * @return int cuántas filas se quitaron (`0` = la consulta no refería a ese médico)
     */
    public function ejecutar(Consulta $consulta, int $cedula, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cedula, $cuando, $origen) {
            $filas = Referencia::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->where('ceduladoctor', $cedula)
                ->get();

            foreach ($filas as $fila) {
                $this->borrarFila($fila, 'referencia', $cuando, $origen);
            }

            return $filas->count();
        });
    }
}
