<?php

namespace App\Services;

use RuntimeException;

/**
 * El proveedor **no tiene** la ruta de decisiones que se pidió (`/v1/systemone` o `/api/v1/decisions`).
 *
 * Existe como clase propia para que `JevService` pueda distinguir "este proveedor no habla el protocolo de
 * Jev" de cualquier otro 404 sin mirar el texto del mensaje, y con `JEV_PROTOCOLO=auto` seguir con la otra
 * ruta de decisión —o, si no hay ninguna, con el camino de chat—.
 */
class JevSinRutaDeDecision extends RuntimeException
{
    public function __construct(public readonly string $ruta, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
