<?php

namespace App\Services;

use RuntimeException;

/**
 * El proveedor de Jev **no tiene la ruta `/v1/systemone`**: es el caso de DefAPI, que sólo expone
 * `/v1/chat/completions` y `/v1/messages` (medido el 2026-10-18).
 *
 * Existe como clase propia para que `JevService` pueda distinguir "este proveedor no habla el protocolo de
 * Jev" de cualquier otro 404 sin mirar el texto del mensaje, y caer al camino de chat cuando el protocolo
 * es `auto`.
 */
class JevSinRutaSystemOne extends RuntimeException
{
}
