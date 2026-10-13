<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\RecipeGrupo;
use App\Models\RecipeGrupoDetalle;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;

/**
 * El rastro de las bajas de un tratamiento (WEB-8.1a), que comparten `GuardarTratamiento` (quitar un medicamento) y
 * `QuitarTratamiento` (quitar el tratamiento entero).
 *
 * Cada baja deja **dos** `deleted` en `sync_changes`:
 *
 *  - el de la tabla del servidor (`recipe_grupo`, `recipe_grupo_detalle`), que es el rastro de auditoría y el que lee
 *    cualquier consumidor que hable con los nombres del esquema legado; y
 *  - el que el **teléfono** entiende (`tratamientos`, `tratamientos_detalle`): el pull del móvil (`eliminados`) trae
 *    `table_name` y `record_id` y la app suelta la fila de la tabla local **que se llama así**; las suyas no se llaman
 *    como las del servidor y un `deleted` de `recipe_grupo` se ignora ("un borrado de una tabla que este teléfono no
 *    guarda se ignora", `LocalDatabase.deleteByRemoteIds`). Sin el segundo, un tratamiento quitado en la web seguiría
 *    ofreciéndose en el teléfono hasta que alguien limpie su copia local. No se toca el móvil (trampa 57 de `TRAMPAS.md`).
 */
trait RastroDeTratamientos
{
    use EscribeConRastro;

    private function bajaDelEncabezado(RecipeGrupo $fila, Carbon $cuando, ?string $origen): void
    {
        $id = (int) $fila->getKey();
        $reg = $fila->reg_medico;

        $this->borrarFila($fila, 'recipe_grupo', $cuando, $origen);

        if ($origen !== null) {
            RegistroDeCambios::registrarOperacion('tratamientos', $id, $reg, 'deleted', null, null, $cuando, $origen);
        }
    }

    private function bajaDelDetalle(RecipeGrupoDetalle $fila, Carbon $cuando, ?string $origen): void
    {
        $id = (int) $fila->getKey();
        $reg = $fila->reg_medico;

        $this->borrarFila($fila, 'recipe_grupo_detalle', $cuando, $origen);

        if ($origen !== null) {
            RegistroDeCambios::registrarOperacion('tratamientos_detalle', $id, $reg, 'deleted', null, null, $cuando, $origen);
        }
    }
}
