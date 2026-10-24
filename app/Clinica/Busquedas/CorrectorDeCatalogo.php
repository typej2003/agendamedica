<?php

namespace App\Clinica\Busquedas;

use App\Clinica\Pacientes\BusquedaCorregida;
use Pecle\Search\Laravel\PecleSearchManager;

/**
 * El corrector de errores de tecleo de los catálogos (PecleSearch), el mismo del buscador de pacientes.
 *
 * El paquete mantiene un índice de palabras por catálogo (`config/pecle_search.php`) y `resolve()` cambia
 * cada palabra que no existe por la que más se le parece: "Hipertension" → "hipertensión", "Gonzlez" →
 * "gonzalez". No toca las de menos de tres letras, las que llevan dígitos ni las que ya existen.
 *
 * Lo que devuelve es **un término**, no una consulta: cada catálogo sigue buscando como siempre (su
 * normalización, su deduplicación, su tope). Si el índice no está construido o el módulo está apagado, el
 * paquete devuelve lo escrito tal cual y el buscador sigue funcionando.
 */
final class CorrectorDeCatalogo
{
    public function __construct(private readonly PecleSearchManager $buscador)
    {
    }

    public function corregir(string $indice, string $buscar): BusquedaCorregida
    {
        $escrito = trim($buscar);

        if ($escrito === '') {
            return BusquedaCorregida::sinCorreccion('');
        }

        $resolucion = $this->buscador->resolve($escrito, $indice);

        return new BusquedaCorregida($escrito, trim($resolucion->applied));
    }
}
