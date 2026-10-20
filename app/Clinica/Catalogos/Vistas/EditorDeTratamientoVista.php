<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\GuardarTratamiento;
use App\Clinica\Consulta\Vistas\ComparteElEditorDeRecipes;
use App\Models\RecipeGrupo;
use Illuminate\Support\Collection;

/**
 * **El editor de un tratamiento del catálogo** (WEB-8.1a): el nombre, la lista de sus medicamentos con su cantidad,
 * indicaciones y orden, y la caja de herramientas para llenarla.
 *
 * Es **el mismo editor del récipe** (`ComparteElEditorDeRecipes`, los mismos parciales de Blade y el mismo
 * `consulta-recipes.js`): un tratamiento y un récipe son una lista de medicamentos con cantidad e indicaciones, y el
 * buscador del vademécum es uno solo. Lo que cambia, y este ViewModel apaga o enciende:
 *
 *  - **no ofrece el modo "Tratamientos"** (un tratamiento no se arma con otros);
 *  - el buscador va **dentro de la misma tarjeta** del nombre y la lista, con un botón al final que abre el alta del
 *    medicamento que falta en un diálogo lateral (`urlCrearMedicamento()`, el alta rápida del vademécum);
 *  - **ofrece subir y bajar** cada medicamento: el `orden` de un tratamiento es el de la lista;
 *  - el guardado es del tratamiento entero y explícito, con el aviso de cambios sin guardar de siempre.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe el tratamiento y sus medicamentos ya leídos y
 * las URLs que resolvió el controlador. Si un `POST` falló, el editor vuelve con lo escrito (el `old()`).
 */
final class EditorDeTratamientoVista
{
    use ComparteElEditorDeRecipes;

    /**
     * @param  RecipeGrupo|null  $tratamiento  el que se edita, o `null` si es uno nuevo
     * @param  Collection<int,object>|null  $items  sus medicamentos (`TratamientosDelCatalogo::itemsDe`)
     * @param  array<string,string>  $urls  `guardar`, `lista`, `medicamentos` (el JSON del buscador) y
     *         `crearMedicamento` (el alta rápida del vademécum, también en JSON)
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló (`[]` en la primera carga)
     * @param  string|null  $nombreInicial  lo que el médico buscó en la lista antes de «Agregar»: el nombre de un tratamiento nuevo
     */
    public function __construct(
        private ?RecipeGrupo $tratamiento,
        private ?Collection $items,
        private array $urls,
        private array $old = [],
        private ?string $nombreInicial = null,
    ) {
    }

    public function esNuevo(): bool
    {
        return $this->tratamiento === null;
    }

    public function titulo(): string
    {
        return $this->esNuevo() ? 'Nuevo tratamiento' : 'Editar tratamiento';
    }

    public function subtitulo(): string
    {
        return $this->esNuevo()
            ? 'Ponle un nombre y agrega los medicamentos que recetas juntos.'
            : 'Código ' . trim((string) $this->tratamiento->codigo) . '. Lo que cambies aquí vale para los récipes que armes de ahora en adelante; los ya hechos no cambian.';
    }

    public function urlLista(): string
    {
        return $this->urls['lista'];
    }

    /** @return array{titulo:string, urlGuardar:string, nombre:string, filas:array<int,array<string,mixed>>} */
    public function editor(): array
    {
        $nombre = (string) ($this->tratamiento->tratamiento ?? '');

        if ($this->esNuevo() && $this->nombreInicial !== null) {
            $nombre = $this->nombreInicial;
        }

        if (($this->old['editor'] ?? null) !== null) {
            $nombre = is_string($this->old['tratamiento'] ?? null) ? $this->old['tratamiento'] : '';
        }

        return [
            'titulo' => $this->titulo(),
            'urlGuardar' => $this->urls['guardar'],
            'nombre' => trim($nombre),
            'filas' => $this->filasDelEditor($this->items),
        ];
    }

    public function largoDelNombre(): int
    {
        return GuardarTratamiento::LARGO_NOMBRE;
    }

    // --------------------------------------------------------------------- Lo que el editor del récipe ofrece, apagado

    public function ofreceTratamientos(): bool
    {
        return false;
    }

    public function ofreceOrdenar(): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------------------------- Textos

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el tratamiento. Si sales ahora, se pierden.';
    }

    public function urlTratamientos(): string
    {
        return '';
    }

    public function textoCrearMedicamento(): string
    {
        return 'Crear y agregar al tratamiento';
    }

    public function botonGuardar(): string
    {
        return $this->esNuevo() ? 'Crear el tratamiento' : 'Guardar el tratamiento';
    }
}
