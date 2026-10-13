<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Models\RecipeGrupo;
use App\Models\RecipeGrupoDetalle;
use Illuminate\Support\Collection;

/**
 * **Los tratamientos del médico para el ABM** (WEB-8.1a): `recipe_grupo` (el encabezado: `codigo` y `tratamiento`)
 * con sus medicamentos de `recipe_grupo_detalle`, que se cruzan por `codigo` dentro del mismo `reg_medico`.
 *
 * Un **tratamiento** es una plantilla que se **copia** al récipe: al cargarlo en la consulta sus medicamentos entran
 * al récipe con su cantidad e indicaciones, y desde ahí no hay ninguna referencia viva al tratamiento. Por eso
 * borrar o editar uno nunca toca un récipe ya hecho.
 *
 * La lectura de los medicamentos de cada tratamiento (con su nombre resuelto por el vademécum) es la **misma** que
 * usa el buscador de la consulta (`RecipesDeLaConsulta::tratamientos`); acá sólo se pide completa.
 */
final class TratamientosDelCatalogo
{
    public function __construct(private RecipesDeLaConsulta $lectura)
    {
    }

    /**
     * Los tratamientos del médico que coinciden con el nombre (todos, si no se escribió nada), con sus medicamentos.
     *
     * @return Collection<int,object{id:int, codigo:string, nombre:string, items:Collection<int,object>}>
     */
    public function listar(string $regMedico, string $buscar = ''): Collection
    {
        $tratamientos = $this->lectura->tratamientos($regMedico, $buscar, null);

        if ($tratamientos->isEmpty()) {
            return collect();
        }

        $ids = RecipeGrupo::where('reg_medico', $regMedico)
            ->whereIn('codigo', $tratamientos->pluck('codigo')->all())
            ->orderBy('id')
            ->get(['id', 'codigo'])
            ->unique(fn ($grupo) => trim((string) $grupo->codigo))
            ->mapWithKeys(fn ($grupo) => [trim((string) $grupo->codigo) => (int) $grupo->id]);

        return $tratamientos
            ->filter(fn ($tratamiento) => $ids->has($tratamiento->codigo))
            ->map(fn ($tratamiento) => (object) [
                'id' => $ids[$tratamiento->codigo],
                'codigo' => $tratamiento->codigo,
                'nombre' => $tratamiento->nombre,
                'items' => $tratamiento->items,
            ])
            ->values();
    }

    /** Cuántos tratamientos tiene el médico (sin búsqueda): decide el estado vacío. */
    public function total(string $regMedico): int
    {
        return RecipeGrupo::where('reg_medico', $regMedico)->count();
    }

    /** Un tratamiento del médico del contexto; el de otro médico no se toca (404 para quien llama). */
    public function encontrar(string $regMedico, int $id): RecipeGrupo
    {
        return RecipeGrupo::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * Los medicamentos del tratamiento **tal como están guardados** (la cantidad y la indicación del tratamiento, sin
     * el respaldo del vademécum que usa el buscador de la consulta), con su nombre resuelto, en el orden del
     * tratamiento (`orden`, `id`). Es lo que se edita: lo que se ve es lo que hay.
     *
     * @return Collection<int,object{codigo:string, nombre:string, generico:?string, cantidad:?int, indicaciones:?string}>
     */
    public function itemsDe(RecipeGrupo $tratamiento): Collection
    {
        $filas = RecipeGrupoDetalle::where('reg_medico', $tratamiento->reg_medico)
            ->where('codigo', trim((string) $tratamiento->codigo))
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        return $this->lectura->itemsDe((string) $tratamiento->reg_medico, $filas);
    }
}
