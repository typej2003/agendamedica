<?php

namespace App\Clinica\Busquedas;

use App\Clinica\Pacientes\BusquedaCorregida;
use Illuminate\Support\Collection;

/**
 * Busca en un catálogo y, si lo escrito **no encontró nada**, vuelve a buscar con el término corregido.
 *
 * Es el mismo criterio que el buscador de pacientes: primero lo escrito —una búsqueda que ya encuentra no
 * paga el corrector— y sólo cuando no hay resultados se resuelve el término con el vocabulario del índice.
 * Si la corrección tampoco encuentra, se devuelven los resultados de lo escrito (vacíos) y la corrección,
 * para que la pantalla pueda decir qué se probó.
 *
 * La búsqueda de cada catálogo no cambia: se le pasa como closure y recibe el término a buscar.
 */
final class BusquedaConCorrector
{
    public function __construct(private readonly CorrectorDeCatalogo $corrector)
    {
    }

    /**
     * @param  callable(string): (Collection<int,mixed>|array<int,mixed>)  $buscar
     * @return array{0: Collection<int,mixed>, 1: BusquedaCorregida}
     */
    public function con(callable $buscar, string $indice, string $termino, bool $literal = false): array
    {
        $escrito = trim($termino);
        $resultados = collect($buscar($escrito));

        if ($literal || $escrito === '' || $resultados->isNotEmpty()) {
            return [$resultados, BusquedaCorregida::sinCorreccion($escrito)];
        }

        $correccion = $this->corrector->corregir($indice, $escrito);

        if ($correccion->corregida()) {
            $resultados = collect($buscar($correccion->aplicado));
        }

        return [$resultados, $correccion];
    }
}
