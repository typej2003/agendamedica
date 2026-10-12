<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\Catalogos\UsoDeUnMedicamento;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Models\Vademecum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Quitar un medicamento del vademécum** (WEB-8.1a): se borra **si no tiene uso**; si lo tiene, no se borra y el
 * resultado dice dónde se usa (decisión del usuario, 2026-10-11; sin columnas nuevas en las tablas legadas).
 *
 * "En uso" es `UsoDeUnMedicamento`: algún récipe de un paciente, el récipe de la pareja o un tratamiento del médico
 * lo nombra por su `codemedicina`. El escritorio no lo comprobaba (su `DELETE` arrastraba los récipes por el
 * `ON DELETE CASCADE` de la base del PowerBuilder); la web no deja que un catálogo se lleve por delante lo ya recetado.
 *
 * ## La comprobación y el borrado van juntos
 *
 * Todo ocurre en **una transacción** y sobre la fila **bajo candado** (`lockForUpdate`): dos bajas o una edición
 * al mismo tiempo se turnan y la segunda ya no encuentra la fila. Lo que ningún candado de esta tabla puede
 * impedir es que, entre la comprobación y el `COMMIT`, otra pantalla guarde un récipe que nombra el medicamento
 * (los récipes no leen el vademécum bajo candado). Ese récipe **no pierde el nombre**: `GuardarRecipe` copia el
 * `NOMBRE/presentación` en `recipes.descripcion` de toda fila nueva y es el respaldo que `RecipesDeLaConsulta::nombreDe`
 * usa cuando el medicamento ya no está en el vademécum. Para una fila del escritorio (sin esa copia) el uso se
 * habría detectado antes.
 *
 * ## El rastro
 *
 * Un `deleted` con `table_name = 'vademecum'` y el id de la fila: es lo que el pull del móvil (`eliminados`) usa
 * para soltarla de su copia local, que se llama igual.
 */
final class QuitarMedicamentoDelCatalogo
{
    use EscribeConRastro;

    public function ejecutar(Vademecum $medicamento, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeBaja
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($medicamento, $cuando, $origen) {
            $fila = Vademecum::whereKey($medicamento->id)->lockForUpdate()->firstOrFail();

            $regMedico = (string) $fila->reg_medico;
            $nombre = (new RecipesDeLaConsulta())->nombreDe($fila, null, trim((string) $fila->codemedicina));

            $uso = UsoDeUnMedicamento::de($regMedico, (string) $fila->codemedicina);

            if ($uso->enUso()) {
                return ResultadoDeBaja::enUso($nombre, $uso->descripcion());
            }

            $this->borrarFila($fila, 'vademecum', $cuando, $origen);

            return ResultadoDeBaja::quitado($nombre);
        });
    }
}
