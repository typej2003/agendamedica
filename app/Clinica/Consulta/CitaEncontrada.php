<?php

namespace App\Clinica\Consulta;

/**
 * La cita que `CitaDeLaConsulta` encontró para una consulta: su id y si el escritorio la movió.
 *
 * `movida` importa porque una cita movida es la fila **vieja** —el paciente ya está en la fecha nueva— y
 * el resto de la web no le ofrece ninguna acción (`MostradorController::citaPorAtender`,
 * `FilaDeCitaVista`): marcarla sería marcar una atención que ese día no ocurrió en ese lugar.
 *
 * Es un objeto de valores y no el modelo `Cola` a propósito: el shell recibe de este lado lo que la
 * pantalla muestra —¿hay cita?, ¿se puede terminar?—, y no una fila de la base con la que podría hacer
 * cualquier cosa (misma regla que el ViewModel: lo que necesita llega por el constructor).
 */
final class CitaEncontrada
{
    public function __construct(
        public readonly int $id,
        public readonly bool $movida,
    ) {
    }

    /** ¿Se puede terminar la atención de esta cita? El escritorio ya la movió, así que no. */
    public function terminable(): bool
    {
        return ! $this->movida;
    }
}
