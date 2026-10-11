<?php

namespace App\Http\Controllers\Clinica\Concerns;

use App\Clinica\Consulta\Vistas\SeccionDeRecipesVista;

/**
 * **Los medicamentos de un récipe en el formulario del editor**: las reglas de validación de `items`, un aviso
 * por regla y la lista de nombres de los avisos de éxito. Lo comparten los dos controladores que guardan un
 * récipe —el del paciente (`ConsultaRecipesController`, WEB-5.1) y el de la pareja
 * (`ConsultaRecipeParejaController`, WEB-5.1b)—: los dos editores son el mismo formulario de filas
 * (`items[k][codemedicina|cantidad|indicaciones]`), así que sus reglas y mensajes no se escriben dos veces.
 */
trait ItemsDeUnRecipeEnElFormulario
{
    /** @return array<string,string> */
    protected function reglasDeLosItems(): array
    {
        return [
            'items' => 'required|array|min:1|max:60',
            'items.*.codemedicina' => 'required|string|max:8',
            'items.*.cantidad' => 'nullable|integer|min:1|max:' . SeccionDeRecipesVista::CANTIDAD_MAXIMA,
            'items.*.indicaciones' => 'nullable|string|max:' . SeccionDeRecipesVista::LARGO_INDICACIONES,
        ];
    }

    /**
     * Un aviso por regla: el `locale` de la app es `en` y la validación sale en inglés si no se le da su
     * texto (trampa 38 de `TRAMPAS.md`).
     *
     * @return array<string,string>
     */
    protected function mensajesDeLosItems(): array
    {
        return [
            'items.required' => 'Agrega al menos un medicamento al récipe.',
            'items.array' => 'Los medicamentos del récipe no llegaron bien: vuelve a abrir el editor.',
            'items.min' => 'Agrega al menos un medicamento al récipe.',
            'items.max' => 'Un récipe no puede llevar más de 60 medicamentos.',
            'items.*.codemedicina.required' => 'A una fila del récipe le falta el medicamento.',
            'items.*.codemedicina.max' => 'El código de un medicamento es demasiado largo.',
            'items.*.cantidad.integer' => 'La cantidad de cada medicamento tiene que ser un número entero.',
            'items.*.cantidad.min' => 'La cantidad mínima de un medicamento es 1.',
            'items.*.cantidad.max' => 'La cantidad de un medicamento no puede pasar de ' . SeccionDeRecipesVista::CANTIDAD_MAXIMA . '.',
            'items.*.indicaciones.max' => 'Las indicaciones de un medicamento no pueden pasar de '
                . SeccionDeRecipesVista::LARGO_INDICACIONES . ' caracteres.',
        ];
    }

    /**
     * «A», «A» y «B», «A», «B» y «C»: nombra lo que pasó con cada medicamento, no con su id.
     *
     * @param  array<int,string>  $nombres
     */
    protected function listar(array $nombres): string
    {
        $etiquetas = array_map(fn ($nombre) => '«' . $nombre . '»', $nombres);

        if (count($etiquetas) === 1) {
            return $etiquetas[0];
        }

        $ultimo = array_pop($etiquetas);

        return implode(', ', $etiquetas) . ' y ' . $ultimo;
    }
}
