<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una cuenta usó la web (panel o `/clinica`) en `$momento`. Lo dispara `App\Http\Middleware\RegistrarActividadWeb`
 * y lo escucha `App\Listeners\GuardarUltimaActividadWeb`, **en la cola**: el request no escribe en la base.
 *
 * Lleva el id y no el modelo (sin `SerializesModels`): el listener no necesita volver a leer al usuario y la
 * fecha viaja como texto ISO porque es la del request, no la de cuando la cola lo procese.
 */
class ActividadWebRegistrada
{
    use Dispatchable;

    public function __construct(public int $userId, public string $momento)
    {
    }
}
