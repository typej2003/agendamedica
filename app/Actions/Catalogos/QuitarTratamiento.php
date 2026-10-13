<?php

namespace App\Actions\Catalogos;

use App\Models\RecipeGrupo;
use App\Models\RecipeGrupoDetalle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Quitar un tratamiento del catálogo** (WEB-8.1a): el encabezado y todos sus medicamentos, **siempre**.
 *
 * A diferencia de un medicamento, un tratamiento no tiene "uso" que proteger: es una plantilla que se **copia** al
 * récipe al cargarla, y desde entonces el récipe no la referencia (los récipes ya hechos no cambian). Por eso se
 * puede borrar siempre —con la confirmación de la pantalla, que dice qué se va—.
 *
 * El escritorio sólo hace `deleterow` del encabezado (`w_agregar_tratamiento_insitu.srw:156`) y deja los
 * medicamentos huérfanos en `recipe_grupo_detalle`, sin dueño y con un `codigo` que el correlativo puede volver a
 * dar; la web los quita con él, en una transacción, para que un código reutilizado no herede medicamentos viejos.
 *
 * El rastro es el de `RastroDeTratamientos`: un `deleted` por fila del servidor y otro con el nombre que entiende el
 * teléfono.
 */
final class QuitarTratamiento
{
    use RastroDeTratamientos;

    public function ejecutar(RecipeGrupo $tratamiento, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeBaja
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($tratamiento, $cuando, $origen) {
            $grupo = RecipeGrupo::whereKey($tratamiento->id)->lockForUpdate()->firstOrFail();
            $nombre = trim((string) $grupo->tratamiento);

            $detalles = RecipeGrupoDetalle::where('reg_medico', $grupo->reg_medico)
                ->where('codigo', trim((string) $grupo->codigo))
                ->get();

            foreach ($detalles as $detalle) {
                $this->bajaDelDetalle($detalle, $cuando, $origen);
            }

            $this->bajaDelEncabezado($grupo, $cuando, $origen);

            return ResultadoDeBaja::quitado($nombre, $detalles->count());
        });
    }
}
