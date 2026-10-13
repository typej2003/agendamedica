<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Consulta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El récipe de la pareja de una consulta** (WEB-5.1b, dentro del ítem 10 del panel del legado): lo que la
 * consulta ya tiene recetado a la pareja del paciente (control prenatal, infecciones de transmisión sexual…) y la
 * comprobación de los medicamentos que llegan del formulario.
 *
 * ## De qué cuelga y qué lo hace distinto del récipe del paciente (verificado en la fuente)
 *
 * Cada medicamento es una fila de **`recipes_pareja`**, que cuelga de la consulta por `nrohistoria` +
 * `nroconsulta` (+ `codemedicina`: `d_recipes_pareja.srd` marca esas tres como clave). A diferencia de
 * `recipes`, la tabla **no tiene columna `recipe` ni cabecera** (`recipe_detalle`), así que la pareja es
 * **una por consulta**: el escritorio la recarga entera con `retrieve(historia, consulta)` y toma la **cédula y
 * el nombre de la primera fila** (`w_consulta_recipe_morado.srw:148-160`). Esos dos datos no tienen tabla
 * propia: viajan **repetidos en todas las filas** (el `INSERT` de cada medicamento los escribe,
 * `:462-480`), y la ventana no deja agregar un medicamento sin ellos (`:444`, «Cargue los datos de la pareja»).
 *
 * ## La pareja es texto libre
 *
 * Decisión del usuario (2026-10-11): la cédula y el nombre **no se enlazan con `pacientes`** —la pareja no
 * es un paciente de este consultorio— y este récipe **no aparece en la historia del paciente principal**.
 *
 * Es **sólo lectura** (más la comprobación de los medicamentos): la escritura es
 * `App\Actions\Consulta\{GuardarRecipeDeLaPareja,EliminarRecipeDeLaPareja}`. El vademécum, los tratamientos y el
 * alta del medicamento que falta son los **mismos** que los del récipe del paciente (`RecipesDeLaConsulta`).
 */
final class RecipeDeLaPareja
{
    public function __construct(private RecipesDeLaConsulta $recipes)
    {
    }

    /**
     * El récipe de la pareja de esa consulta, o `null` si no tiene. La cédula y el nombre salen de la primera
     * fila (la que carga el escritorio) o, si esa no los trae, de la primera que sí.
     *
     * @return object{cedula:string, nombre:string, items:Collection<int,object>}|null
     */
    public function de(string $regMedico, int $numhistoria, int $nroconsulta): ?object
    {
        $filas = DB::table('recipes_pareja')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numhistoria)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'codemedicina', 'cantidad', 'indicaciones', 'descripcion', 'orden', 'cedula', 'nombre']);

        if ($filas->isEmpty()) {
            return null;
        }

        $primera = fn (string $columna): string => (string) ($filas
            ->map(fn ($fila) => CamposDePaciente::texto($fila->{$columna}))
            ->first(fn ($valor) => $valor !== null) ?? '');

        return (object) [
            'cedula' => $primera('cedula'),
            'nombre' => $primera('nombre'),
            'items' => $this->recipes->itemsDe($regMedico, $filas),
        ];
    }

    /** ¿La consulta ya tiene récipe de la pareja? */
    public function existe(Consulta $consulta): bool
    {
        return $this->filas($consulta)->exists();
    }

    /**
     * Los medicamentos que llegaron del formulario, **listos para `GuardarRecipeDeLaPareja`** y comprobados
     * contra los catálogos del médico: la misma regla que el récipe del paciente
     * (`RecipesDeLaConsulta::resolverItemsContra`: sin texto libre, cantidad 1 por defecto y el nombre lo pone el
     * servidor), contra lo que la pareja ya tiene.
     *
     * @param  array<int,array<string,mixed>>  $items  los `items` del formulario
     * @return array{items:array<int,array{codemedicina:string, cantidad:int, indicaciones:?string, descripcion:?string, nombre:string}>, desconocidos:array<int,string>}
     */
    public function resolverItems(Consulta $consulta, array $items): array
    {
        $actuales = [];

        foreach ($this->filas($consulta)->get(['codemedicina', 'descripcion']) as $fila) {
            $actuales[trim((string) $fila->codemedicina)] = CamposDePaciente::texto($fila->descripcion);
        }

        return $this->recipes->resolverItemsContra($consulta, $actuales, $items);
    }

    /** Las filas de `recipes_pareja` de la consulta, acotadas por médico, historia y número de consulta. */
    private function filas(Consulta $consulta): \Illuminate\Database\Query\Builder
    {
        return DB::table('recipes_pareja')
            ->where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta);
    }
}
