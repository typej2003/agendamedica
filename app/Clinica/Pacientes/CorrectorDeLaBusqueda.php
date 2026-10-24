<?php

namespace App\Clinica\Pacientes;

use Pecle\Search\Laravel\PecleSearchManager;

/**
 * La corrección de errores de tecleo del buscador de pacientes (PecleSearch).
 *
 * El paquete mantiene un **índice de palabras** de los nombres del consultorio (`search_tokens`) y un
 * corrector que, antes de buscar, cambia cada palabra que no existe por la que más se le parece:
 * "Gonzlez" → "gonzalez", "Herera" → "herrera", "Perezz" → "perez". No toca las palabras de menos de
 * tres letras, las que llevan dígitos (cédula, n.º de historia) ni las que ya existen o se están
 * escribiendo como comienzo de una que sí existe.
 *
 * Lo que devuelve es **un término**, no una consulta: quien busca sigue siendo `BusquedaDePacientes`.
 * Si el índice no está construido (o el módulo está apagado) el paquete devuelve lo escrito tal cual,
 * así que esto nunca deja el buscador sin funcionar.
 */
final class CorrectorDeLaBusqueda
{
    /** El nombre del índice en `config/pecle_search.php`. */
    public const INDICE = 'pacientes';

    public function __construct(private readonly PecleSearchManager $buscador)
    {
    }

    public function corregir(string $buscar): BusquedaCorregida
    {
        $escrito = trim($buscar);

        if ($escrito === '') {
            return BusquedaCorregida::sinCorreccion('');
        }

        $resolucion = $this->buscador->resolve($escrito, self::INDICE);

        return new BusquedaCorregida($escrito, trim($resolucion->applied));
    }
}
