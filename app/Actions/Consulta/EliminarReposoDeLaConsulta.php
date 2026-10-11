<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\ReposoPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar el reposo de una consulta** (WEB-5.3a). Siempre se puede, sin límite de tiempo.
 *
 * Borra la fila de `reposo_paciente` de la consulta (y deja su `deleted` en `sync_changes`, que es lo que el móvil usa
 * para soltarla). No deja la plantilla de 1 día: la plantilla la crea el **escritorio** al abrir la consulta, no la web,
 * y una consulta sin fila de reposo es una consulta sin reposo.
 */
final class EliminarReposoDeLaConsulta
{
    use EscribeConRastro;

    /**
     * @return int cuántas filas se quitaron (`0` = la consulta no tenía reposo)
     */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $cuando, $origen) {
            $filas = ReposoPaciente::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($filas as $fila) {
                $this->borrarFila($fila, 'reposo_paciente', $cuando, $origen);
            }

            return $filas->count();
        });
    }
}
