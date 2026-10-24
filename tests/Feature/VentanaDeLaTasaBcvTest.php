<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * **La ventana en la que corre `tasas:bcv`** (2026-10-23): de 5:00 pm a 9:00 pm hora de Caracas. El BCV publica
 * de 4 a 7 pm y las dos horas de margen cubren los timeouts; fuera de ahí la tasa no cambia, así que no corre.
 * La app corre en UTC: Caracas es UTC-4.
 */
class VentanaDeLaTasaBcvTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function correEn(string $utc): bool
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'tasas:bcv'));

        $this->assertNotNull($evento, 'tasas:bcv no está programado.');

        return $evento->filtersPass($this->app);
    }

    public function test_no_corre_de_madrugada_ni_de_dia(): void
    {
        $this->assertFalse($this->correEn('2026-10-23 09:00:00'), '5:00 am en Caracas');
        $this->assertFalse($this->correEn('2026-10-23 16:00:00'), '12:00 m en Caracas');
        $this->assertFalse($this->correEn('2026-10-23 20:59:00'), '4:59 pm en Caracas');
    }

    public function test_corre_de_5_a_9_pm_de_caracas(): void
    {
        $this->assertTrue($this->correEn('2026-10-23 21:00:00'), '5:00 pm en Caracas');
        $this->assertTrue($this->correEn('2026-10-23 23:30:00'), '7:30 pm en Caracas');
        $this->assertTrue($this->correEn('2026-10-24 01:00:00'), '9:00 pm en Caracas');
    }

    public function test_no_corre_despues_de_las_9_pm(): void
    {
        $this->assertFalse($this->correEn('2026-10-24 01:30:00'), '9:30 pm en Caracas');
    }
}
