<?php

namespace App\Notificaciones;

use RuntimeException;

/**
 * El envío **ni siquiera se intentó**: al paciente le falta el dato del canal, o el proveedor del
 * servidor no está configurado.
 *
 * Es distinto de un envío que el proveedor **rechazó**: eso sí se intentó y queda registrado como
 * `NotificacionCita::ESTADO_FALLIDA` con la respuesta cruda del proveedor. Acá no hay nada que
 * registrar.
 *
 * `estadoHttp` lo usa el endpoint del móvil para responder lo mismo que respondía antes (422 si
 * falta el dato, 503 si el servidor no tiene proveedor); la web lo muestra como mensaje de error.
 */
final class ExcepcionDeEnvio extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $estadoHttp = 422)
    {
        parent::__construct($mensaje);
    }
}
