<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **La cola en la base** (2026-10-24): producción pasa de `QUEUE_CONNECTION=sync` a `database`. Hay dos piezas
 * que no pueden desaparecer sin que la cola se detenga en silencio: la tabla `jobs` y el worker programado.
 * Las dos se cubren acá, porque un fallo de cualquiera deja los jobs acumulándose sin que nada avise.
 */
class WorkerDeLaColaTest extends TestCase
{
    public function test_la_tabla_jobs_existe(): void
    {
        $this->assertTrue(
            Schema::hasTable('jobs'),
            'Falta la tabla `jobs` (migración 2026_10_24_120000): sin ella `QUEUE_CONNECTION=database` no encola.'
        );
    }

    public function test_el_worker_corre_cada_minuto_y_termina_solo(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'queue:work'));

        $this->assertNotNull($evento, 'El worker de la cola no está programado: los jobs quedarían sin ejecutar.');

        $this->assertSame(
            '* * * * *',
            $evento->expression,
            'Tiene que despertar cada minuto: es el único consumidor de la cola.'
        );

        // Sin `--stop-when-empty` el worker se queda esperando y `schedule:run` no termina nunca; sin
        // `--max-time` una corrida larga se solapa con la siguiente.
        $this->assertStringContainsString('--stop-when-empty', $evento->command);
        $this->assertStringContainsString('--max-time=', $evento->command);
    }
}
