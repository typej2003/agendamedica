<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\RecipePareja;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar el récipe de la pareja entero** (WEB-5.1b): todas las filas de `recipes_pareja` de la consulta.
 * Siempre se puede, sin límite de tiempo (decisión del usuario, 2026-10-11).
 *
 * `recipes_pareja` no tiene cabecera: la pareja y su cédula y nombre **son** sus filas, así que eliminar el
 * récipe es borrar todas, en una transacción. El escritorio borra un medicamento a la vez (`DeleteRow` +
 * `dw_9.update()`, `w_consulta_recipe_morado.srw:152-154`) y, al borrar el último, la pareja deja de existir.
 *
 * Cada fila borrada deja un `deleted` en `sync_changes` (`table_name = 'recipes_pareja'`, su `record_id`). El
 * `created` de las filas que la web había creado **se deja**: ya no hay a quién proteger y quitarlo sería
 * reescribir la bitácora.
 */
final class EliminarRecipeDeLaPareja
{
    /**
     * @param  Consulta  $consulta  ya resuelta contra el contexto
     * @return int cuántas filas se quitaron (`0` = la consulta no tenía récipe de la pareja)
     */
    public function ejecutar(Consulta $consulta, ?CarbonInterface $cuando = null, string $origen = 'web'): int
    {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $regMedico = (string) $consulta->reg_medico;

        return DB::transaction(function () use ($consulta, $cuando, $origen, $regMedico) {
            $filas = RecipePareja::where('reg_medico', $regMedico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->get();

            foreach ($filas as $fila) {
                $id = (int) $fila->id;
                $fila->delete();
                RegistroDeCambios::registrarOperacion('recipes_pareja', $id, $regMedico, 'deleted', null, null, $cuando, $origen);
            }

            return $filas->count();
        });
    }
}
