<?php

namespace App\Services\IA;

/**
 * Lo que devuelve una herramienta del chatbot: los datos que se le pasan al modelo para que redacte, o una
 * **respuesta final** que ya está lista y no hay que volver a preguntarle (la confirmación de una reserva, el
 * aviso de una cita pendiente).
 */
final class ResultadoDeHerramienta
{
    /**
     * @param  array<string,mixed>  $datos  lo que se le devuelve al modelo como resultado de la tool
     * @param  string|null  $respuestaFinal  si viene, es la respuesta que se le manda al paciente tal cual
     */
    public function __construct(
        public readonly array $datos = [],
        public readonly ?string $respuestaFinal = null,
    ) {}
}
