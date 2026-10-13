<?php

namespace App\Clinica\Catalogos;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Pagina en memoria una lista ya filtrada y ordenada de un catálogo (WEB-8.1b). Los catálogos de un médico son de
 * cientos de filas (el buscador sin tildes no es un `LIKE` de SQL, trampa 13), así que se leen, se filtran en PHP y se
 * cortan en páginas de 25: es lo mismo que hace `MedicamentosDelCatalogo` (8.1a).
 */
trait PaginaUnaColeccion
{
    public const POR_PAGINA = 25;

    /**
     * @param  Collection<int,mixed>  $filas
     * @param  string  $ruta  la URL de la pantalla, para los enlaces del paginador
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda y los filtros)
     */
    protected function paginar(Collection $filas, int $pagina, string $ruta, array $query): LengthAwarePaginator
    {
        $pagina = max(1, $pagina);

        return new LengthAwarePaginator(
            $filas->forPage($pagina, self::POR_PAGINA)->values(),
            $filas->count(),
            self::POR_PAGINA,
            $pagina,
            ['path' => $ruta, 'query' => $query],
        );
    }
}
