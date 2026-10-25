<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **La cola en la base** (2026-10-24): producción pasa de `QUEUE_CONNECTION=sync` a `database`. La pieza que no
 * puede desaparecer sin que la cola se detenga en silencio es la tabla `jobs`: sin ella, el `dispatch` de
 * cualquier request autenticado falla y el usuario ve un 500.
 *
 * El **worker no se prueba acá** porque no vive en el código: corre desde un cron de Plesk
 * (`queue:work --stop-when-empty`, ver AGENTS.md). Estuvo un rato programado en `Kernel::schedule()` y se sacó
 * el mismo día: compartía el minuto con `pagos:conciliar` y su salida se perdía en el `/dev/null` del cron.
 */
class ColaEnBaseDeDatosTest extends TestCase
{
    public function test_la_tabla_jobs_existe(): void
    {
        $this->assertTrue(
            Schema::hasTable('jobs'),
            'Falta la tabla `jobs` (migración 2026_10_24_120000): sin ella `QUEUE_CONNECTION=database` no encola.'
        );
    }
}
