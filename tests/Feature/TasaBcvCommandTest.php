<?php

namespace Tests\Feature;

use App\Models\ConfiguracionPago;
use App\Models\TasaDeCambio;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **La sincronización automática de la tasa del BCV** (`tasas:bcv`, 2026-10-21).
 *
 * Lo que se fija:
 *  - guarda la tasa leída con origen `bcv`;
 *  - no duplica filas cuando el BCV publica el mismo valor;
 *  - guarda una nueva cuando cambia;
 *  - en modo manual no toca nada;
 *  - si el BCV falla, la tasa anterior sigue vigente.
 */
class TasaBcvCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** Lo que devuelve el BCV en esta prueba; se cambia entre corridas. */
    private string $tasaBcv = '36,1234';

    private int $estadoBcv = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function () {
            if ($this->estadoBcv !== 200) {
                return Http::response('', $this->estadoBcv);
            }

            return Http::response(
                '<html><body><div id="dolar"><strong>' . $this->tasaBcv . '</strong></div></body></html>',
                200
            );
        });
    }

    public function test_sincroniza_la_tasa_del_bcv(): void
    {
        ConfiguracionPago::actual()->update(['tasa_origen' => ConfiguracionPago::TASA_BCV]);

        $this->artisan('tasas:bcv')->assertExitCode(0);

        $tasa = TasaDeCambio::actual();
        $this->assertNotNull($tasa);
        $this->assertSame(TasaDeCambio::BCV, $tasa->origen);
        $this->assertSame('36.123400', (string) $tasa->tasa);
    }

    public function test_no_duplica_la_fila_si_la_tasa_no_cambio(): void
    {
        ConfiguracionPago::actual()->update(['tasa_origen' => ConfiguracionPago::TASA_BCV]);

        $this->artisan('tasas:bcv')->assertExitCode(0);
        $this->artisan('tasas:bcv')->assertExitCode(0);

        $this->assertSame(1, TasaDeCambio::where('origen', TasaDeCambio::BCV)->count());
    }

    public function test_guarda_una_tasa_nueva_cuando_el_bcv_cambia(): void
    {
        ConfiguracionPago::actual()->update(['tasa_origen' => ConfiguracionPago::TASA_BCV]);

        $this->tarifaBcv('36,1234');
        $this->artisan('tasas:bcv')->assertExitCode(0);

        $this->tarifaBcv('36,5000');
        $this->artisan('tasas:bcv')->assertExitCode(0);

        $this->assertSame(2, TasaDeCambio::where('origen', TasaDeCambio::BCV)->count());
        $this->assertSame('36.500000', (string) TasaDeCambio::actual()->tasa);
    }

    public function test_en_modo_manual_no_toca_nada(): void
    {
        ConfiguracionPago::actual()->update(['tasa_origen' => ConfiguracionPago::TASA_MANUAL]);

        $this->tarifaBcv('40,0000');
        $this->artisan('tasas:bcv')->assertExitCode(0);

        $this->assertSame(0, TasaDeCambio::where('origen', TasaDeCambio::BCV)->count());
    }

    public function test_el_dry_run_no_guarda(): void
    {
        ConfiguracionPago::actual()->update(['tasa_origen' => ConfiguracionPago::TASA_BCV]);

        $this->artisan('tasas:bcv', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, TasaDeCambio::where('origen', TasaDeCambio::BCV)->count());
    }

    public function test_si_el_bcv_falla_la_tasa_anterior_sigue_vigente(): void
    {
        ConfiguracionPago::actual()->update(['tasa_origen' => ConfiguracionPago::TASA_BCV]);

        $this->artisan('tasas:bcv')->assertExitCode(0);

        $this->estadoBcv = 500;
        $this->artisan('tasas:bcv')->assertExitCode(1);

        $this->assertSame('36.123400', (string) TasaDeCambio::actual()->tasa);
    }

    private function tarifaBcv(string $valor): void
    {
        $this->tasaBcv = $valor;
    }
}
