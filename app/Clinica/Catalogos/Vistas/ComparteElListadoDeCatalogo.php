<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Clinica\Vistas\ConValoresDeFormulario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lo que comparten los ViewModels de los catálogos con listado paginado y diálogo lateral (WEB-8.1b): el índice de
 * catálogos, el buscador, el paginador, la URL de la misma lista con sus parámetros y el diálogo de alta y edición.
 *
 * Quien lo usa declara las propiedades `$filas` (el paginador), `$buscar`, `$base` (la URL de la pantalla sin
 * parámetros), `$urls` (`guardar`, y `quitar` como función de id), `$editando` (la fila que se edita o `null`) y
 * `$abrirNuevo`, y los métodos `slug()` (el del índice) y `filtros()` (los parámetros de la lista que no son la búsqueda,
 * como el tipo de examen). Es la misma forma que `MedicamentosVista` (8.1a), escrita una vez para las cuatro pantallas nuevas.
 */
trait ComparteElListadoDeCatalogo
{
    use ConValoresDeFormulario;

    /** El `slug` de esta pantalla en `CatalogosDisponibles`. */
    abstract protected function slug(): string;

    /**
     * Los parámetros del listado que hay que conservar además de la búsqueda (un filtro propio de la pantalla).
     *
     * @return array<string,string>
     */
    protected function filtros(): array
    {
        return [];
    }

    // ----------------------------------------------------------------------------------------- Encabezado

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function hayBusqueda(): bool
    {
        return $this->buscar !== '';
    }

    /** ¿Hay algo que acotar la lista (búsqueda o filtro)? */
    public function hayFiltro(): bool
    {
        return $this->buscar !== '' || $this->filtros() !== [];
    }

    public function urlBase(): string
    {
        return $this->base;
    }

    /** La lista sin búsqueda ni filtros: a dónde vuelve «Limpiar». */
    public function urlLimpiar(): string
    {
        return $this->base;
    }

    /** ¿El diálogo nace abierto? Sí al editar o al venir de «Nuevo» (`?nuevo=1`). */
    public function abrirAlCargar(): bool
    {
        return $this->editando() || $this->abrirNuevo;
    }

    /** «Nuevo»: la misma lista, con el diálogo abierto y vacío (la búsqueda, el filtro y la página se conservan). */
    public function urlNuevo(): string
    {
        return $this->url(['nuevo' => 1]);
    }

    // ------------------------------------------------------------------------------------------- La lista

    public function paginador(): LengthAwarePaginator
    {
        return $this->filas;
    }

    public function haySobrantes(): bool
    {
        return $this->filas->hasPages();
    }

    /** "Mostrando 1–25 de 238", o vacío cuando no hay filas. */
    public function rango(): string
    {
        if ($this->filas->total() === 0) {
            return '';
        }

        return 'Mostrando ' . $this->filas->firstItem() . '–' . $this->filas->lastItem() . ' de ' . $this->filas->total();
    }

    public function estaVacio(): bool
    {
        return $this->filas->total() === 0;
    }

    /**
     * La lista con lo que hay que conservar (la búsqueda, el filtro y la página) y lo que pida `$query` encima.
     *
     * @param  array<string,mixed>  $query
     */
    protected function url(array $query = []): string
    {
        $parametros = array_filter(array_merge([
            'buscar' => $this->buscar,
            'page' => $this->filas->currentPage() > 1 ? $this->filas->currentPage() : null,
        ], $this->filtros(), $query), fn ($valor) => $valor !== null && $valor !== '');

        return $parametros === [] ? $this->base : $this->base . '?' . http_build_query($parametros);
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function urlGuardar(): string
    {
        return $this->urls['guardar'];
    }

    /** Cancelar vuelve a la misma lista (la búsqueda, el filtro y la página se conservan). */
    public function urlCancelar(): string
    {
        return $this->url();
    }

    /** Lo que lleva el formulario para volver a la misma lista: la búsqueda, el filtro y la página. */
    public function parametrosDeVuelta(): array
    {
        return array_merge(['buscar' => $this->buscar], $this->filtros(), ['page' => (string) $this->filas->currentPage()]);
    }
}
