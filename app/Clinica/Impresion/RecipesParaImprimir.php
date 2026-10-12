<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\RecipeDeLaPareja;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Consulta;
use App\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * **Los récipes de una consulta, listos para imprimir** (WEB-5.2): qué hay para imprimir (los récipes del
 * paciente, numerados, y el de la pareja) y cada uno armado como `DocumentoDeRecipe`.
 *
 * No agrupa ni nombra nada por su cuenta: usa la lectura de la sección de récipes (`RecipesDeLaConsulta` y
 * `RecipeDeLaPareja`), que es **la** definición de qué es un récipe y cómo se resuelve el nombre de sus
 * medicamentos. Lo que agrega es lo que sólo la hoja necesita: los nombres comercial y genérico por separado
 * y los datos del paciente.
 *
 * ## Qué se imprime en el lugar del paciente
 *
 *  - **Récipe del paciente**: su nombre, su cédula con la nacionalidad (`V-12345678`), su edad **a la fecha del
 *    récipe** (no a la de hoy: un récipe reimpreso años después sigue diciendo la edad que tenía) y su dirección.
 *  - **Récipe de la pareja**: la cédula y el nombre que se cargaron para la pareja, **sin edad ni dirección**
 *    (no se conocen) y **sin el nombre del paciente principal**. El legado hace igual con la pareja
 *    (`d_recipe_completo_morado`: `PACIENTE` = `recipes_pareja.nombre`, `C.I.` = `recipes_pareja.cedula`) y, en
 *    una receta que lleva la pareja a la farmacia por una infección de transmisión sexual, el nombre del otro
 *    paciente es un dato que no tiene por qué viajar en ella. (El legado imprime además la **edad del
 *    paciente principal** en la hoja de la pareja: es un descuido de la fuente y no se replica.)
 *
 * Es de **sólo lectura**.
 */
final class RecipesParaImprimir
{
    public function __construct(
        private RecipesDeLaConsulta $recipes,
        private RecipeDeLaPareja $pareja,
    ) {
    }

    /**
     * Lo que se puede imprimir de la consulta: cada récipe del paciente (con su número) y, si la consulta lo
     * tiene, el de la pareja. Un récipe **sin medicamentos** no se ofrece: no hay nada que poner en la hoja.
     *
     * @return array<int,array{clave:string, titulo:string, resumen:string, fecha:?string, de_la_pareja:bool}>
     */
    public function disponibles(Consulta $consulta): array
    {
        $disponibles = [];

        foreach ($this->recipes->deLaConsulta((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta) as $recipe) {
            if ($recipe->items->isEmpty()) {
                continue;
            }

            $disponibles[] = [
                'clave' => (string) $recipe->numero,
                'titulo' => 'Récipe ' . $recipe->numero,
                'resumen' => $this->cuenta($recipe->items->count()),
                'fecha' => $recipe->fecha,
                'de_la_pareja' => false,
            ];
        }

        $pareja = $this->pareja->de((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta);

        if ($pareja !== null && $pareja->items->isNotEmpty()) {
            $disponibles[] = [
                'clave' => DocumentoDeRecipe::PAREJA,
                'titulo' => 'Récipe de la pareja',
                'resumen' => $this->cuenta($pareja->items->count()),
                'fecha' => null,
                'de_la_pareja' => true,
            ];
        }

        return $disponibles;
    }

    /**
     * El récipe pedido por su clave (`'2'` o `'pareja'`), o `null` si la consulta no lo tiene o no tiene
     * medicamentos.
     */
    public function documento(Consulta $consulta, int $pacienteId, string $clave): ?DocumentoDeRecipe
    {
        $regMedico = (string) $consulta->reg_medico;
        $fechaDeLaConsulta = Carbon::parse($consulta->fecha)->toDateString();

        if ($clave === DocumentoDeRecipe::PAREJA) {
            $pareja = $this->pareja->de($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta);

            if ($pareja === null || $pareja->items->isEmpty()) {
                return null;
            }

            return new DocumentoDeRecipe(
                DocumentoDeRecipe::PAREJA,
                'Récipe de la pareja',
                null,
                $fechaDeLaConsulta,
                [
                    'nombre' => $pareja->nombre,
                    'cedula' => CamposDePaciente::texto($pareja->cedula),
                    'edad' => null,
                    'direccion' => null,
                ],
                $this->items($pareja->items),
            );
        }

        if (! ctype_digit($clave)) {
            return null;
        }

        $recipe = $this->recipes
            ->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta)
            ->first(fn (object $recipe) => $recipe->numero === (int) $clave);

        $paciente = Paciente::find($pacienteId);

        if ($recipe === null || $recipe->items->isEmpty() || $paciente === null) {
            return null;
        }

        $fecha = $recipe->fecha ?? $fechaDeLaConsulta;

        return new DocumentoDeRecipe(
            DocumentoDeRecipe::PACIENTE,
            'Récipe ' . $recipe->numero,
            $recipe->numero,
            $fecha,
            // La cabecera del paciente es la misma de todos los documentos impresos (`PacienteImpreso`).
            PacienteImpreso::datos($paciente, $fecha),
            $this->items($recipe->items),
        );
    }

    /**
     * @param  Collection<int,object>  $items  lo que devuelve `RecipesDeLaConsulta::itemsDe`
     * @return array<int,ItemImpreso>
     */
    private function items(Collection $items): array
    {
        return $items->map(fn (object $item) => ItemImpreso::deLaConsulta($item))->values()->all();
    }

    private function cuenta(int $total): string
    {
        return $total . ($total === 1 ? ' medicamento' : ' medicamentos');
    }
}
