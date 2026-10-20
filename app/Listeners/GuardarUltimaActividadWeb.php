<?php

namespace App\Listeners;

use App\Events\ActividadWebRegistrada;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Escribe `users.ultima_actividad_web_at` desde la cola (el worker es un proceso largo y reusa su conexión de
 * escritura; el request web no abre ninguna).
 *
 * - Un solo `UPDATE` por el query builder: no lee al usuario, no mueve `updated_at` ni dispara eventos del modelo.
 * - **Sólo avanza**: los jobs pueden procesarse fuera de orden (varios workers, reintentos), así que la condición
 *   `IS NULL OR < momento` impide que uno viejo pise una fecha más nueva.
 * - Si el usuario ya no existe, el `UPDATE` no toca nada y el job termina bien: no hay nada que reintentar.
 */
class GuardarUltimaActividadWeb implements ShouldQueue
{
    /** Es un dato de presentación: si falla, el próximo request lo vuelve a mandar. */
    public $tries = 1;

    public function handle(ActividadWebRegistrada $evento): void
    {
        DB::table('users')
            ->where('id', $evento->userId)
            ->where(fn ($q) => $q->whereNull('ultima_actividad_web_at')
                ->orWhere('ultima_actividad_web_at', '<', $evento->momento))
            ->update(['ultima_actividad_web_at' => $evento->momento]);
    }
}
