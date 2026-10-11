<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\Recipe;
use App\Models\RecipeDetalle;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Eliminar un récipe entero de una consulta** (WEB-5.1): todas las filas de `recipes` del récipe y su
 * cabecera de `recipe_detalle`. Siempre se puede, sin límite de tiempo (decisión del usuario, 2026-10-11).
 *
 * El escritorio borra un medicamento a la vez (`DeleteRow` + `dw_9.update()`, `w_consulta_principal.srw:13286-13288`);
 * quitar un récipe entero es el mismo `DELETE` por cada fila más la cabecera, en una transacción: un récipe
 * a medias (la cabecera sin filas, o filas sin cabecera) no tiene sentido ni para imprimir ni para el sync.
 *
 * ## El rastro, y qué pasa en el teléfono
 *
 * Cada fila borrada deja un `deleted` en `sync_changes` con `table_name = 'recipes'` y su `record_id`:
 * **es lo que el pull del móvil (`SyncAppDataController` → `eliminados`) usa para soltar la fila**, y la
 * copia local de `recipes` del teléfono borra por ese id. La cabecera deja el suyo bajo `recipe_detalle`
 * (el teléfono no la guarda; es para la auditoría). El `created` de la cabecera, que es la convención
 * heredada que protege a la cabecera del reemplazo de `recipe_detalle` del escritorio, **se deja**: ya no
 * hay a quién proteger y quitarlo sería reescribir la bitácora.
 */
final class EliminarRecipe
{
    /**
     * @param  Consulta  $consulta  ya resuelta contra el contexto
     * @param  int  $numero  el récipe a eliminar (el `NULL` del escritorio es el 1)
     * @return int cuántas filas de `recipes` se quitaron (`0` y sin cabecera = el récipe no existía)
     */
    public function ejecutar(Consulta $consulta, int $numero, ?CarbonInterface $cuando = null, string $origen = 'web'): int
    {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $regMedico = (string) $consulta->reg_medico;

        return DB::transaction(function () use ($consulta, $numero, $cuando, $origen, $regMedico) {
            $filas = Recipe::where('reg_medico', $regMedico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->whereRaw('COALESCE(recipe, 1) = ?', [$numero])
                ->get();

            foreach ($filas as $fila) {
                $id = (int) $fila->id;
                $fila->delete();
                RegistroDeCambios::registrarOperacion('recipes', $id, $regMedico, 'deleted', null, null, $cuando, $origen);
            }

            $cabeceras = RecipeDetalle::where('reg_medico', $regMedico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->where('recipe', $numero)
                ->get();

            foreach ($cabeceras as $cabecera) {
                $id = (int) $cabecera->id;
                $cabecera->delete();
                RegistroDeCambios::registrarOperacion('recipe_detalle', $id, $regMedico, 'deleted', null, null, $cuando, $origen);
            }

            return $filas->count();
        });
    }
}
