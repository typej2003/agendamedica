<?php

namespace Tests\Feature;

use App\Models\ConfiguracionPago;
use App\Models\Liquidacion;
use App\Models\Pago;
use App\Pagos\Liquidaciones\GenerarLiquidaciones;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * **Liquidaciones T+1**: lo que la plataforma recaudó para un médico se agrupa por consultorio y se paga.
 *
 * Lo que se fija: sólo entran pagos aprobados, de citas, cobrados por la plataforma y ya disponibles; el
 * neto respeta el mínimo; un pago no entra en dos liquidaciones; y marcar pagada deja la fecha y la
 * referencia.
 */
class LiquidacionesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        ConfiguracionPago::actual()->update([
            'liquidacion_dias' => 1,
            'liquidacion_minima_usd_e2' => 0,
        ]);
    }

    private function pago(string $reg, int $brutoUsdE2, int $comisionUsdE2, array $extra = []): Pago
    {
        return Pago::create(array_merge([
            'reg_medico' => $reg,
            'beneficiario_tipo' => Pago::BENEFICIARIO_MEDICO,
            'beneficiario_reg_medico' => $reg,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
            'canal' => Pago::CANAL_LINK,
            'pagable_tipo' => Pago::PAGABLE_CITA,
            'pagable_id' => 1,
            'concepto' => 'Cita de prueba',
            'moneda' => 'USD',
            'monto_e2' => $brutoUsdE2,
            'monto_usd_e2' => $brutoUsdE2,
            'comision_total_usd_e2' => $comisionUsdE2,
            'neto_usd_e2' => $brutoUsdE2 - $comisionUsdE2,
            'neto_ves_e2' => 0,
            'estado' => Pago::APROBADO,
            'pagado_el' => now()->subHours(2),
            'disponible_el' => now()->subDay()->toDateString(),
            'estado_liquidacion' => Pago::LIQUIDACION_PENDIENTE,
        ], $extra));
    }

    public function test_agrupa_los_pagos_del_mismo_medico_en_una_liquidacion(): void
    {
        $a = $this->pago('liq-1', 10000, 1000);
        $b = $this->pago('liq-1', 5000, 500);

        $liquidaciones = app(GenerarLiquidaciones::class)->ejecutar();

        $this->assertCount(1, $liquidaciones);
        $liquidacion = $liquidaciones->first();

        $this->assertSame('liq-1', $liquidacion->reg_medico);
        $this->assertSame(15000, (int) $liquidacion->total_bruto_usd_e2);
        $this->assertSame(1500, (int) $liquidacion->total_comisiones_usd_e2);
        $this->assertSame(13500, (int) $liquidacion->total_neto_usd_e2);
        $this->assertSame(Liquidacion::CONFIRMADA, $liquidacion->estado);

        $this->assertSame(2, $liquidacion->detalle()->count());
        $this->assertSame(Pago::LIQUIDACION_LIQUIDADO, $a->fresh()->estado_liquidacion);
        $this->assertSame($liquidacion->id, (int) $a->fresh()->liquidacion_id);
        $this->assertSame($liquidacion->id, (int) $b->fresh()->liquidacion_id);
    }

    public function test_no_liquida_un_pago_que_todavia_no_esta_disponible(): void
    {
        $this->pago('liq-2', 10000, 0, ['disponible_el' => now()->addDay()->toDateString()]);

        $this->assertCount(0, app(GenerarLiquidaciones::class)->ejecutar());
    }

    public function test_no_genera_si_el_neto_no_llega_al_minimo(): void
    {
        ConfiguracionPago::actual()->update(['liquidacion_minima_usd_e2' => 100_000]);
        $this->pago('liq-3', 10000, 0);

        $this->assertCount(0, app(GenerarLiquidaciones::class)->ejecutar());
    }

    public function test_un_pago_ya_liquidado_no_entra_en_otra(): void
    {
        $this->pago('liq-4', 10000, 0);

        $primera = app(GenerarLiquidaciones::class)->ejecutar();
        $segunda = app(GenerarLiquidaciones::class)->ejecutar();

        $this->assertCount(1, $primera);
        $this->assertCount(0, $segunda);
    }

    public function test_marcar_pagada_guarda_fecha_y_referencia(): void
    {
        $this->pago('liq-5', 10000, 0);
        $liquidacion = app(GenerarLiquidaciones::class)->ejecutar()->first();

        app(GenerarLiquidaciones::class)->pagar($liquidacion, 'TRF-123');

        $liquidacion->refresh();
        $this->assertSame(Liquidacion::PAGADA, $liquidacion->estado);
        $this->assertNotNull($liquidacion->pagada_el);
        $this->assertSame('TRF-123', $liquidacion->referencia);
    }

    public function test_el_comando_genera_y_paga(): void
    {
        $this->pago('liq-6', 10000, 0);

        $this->artisan('liquidaciones:generar')->assertSuccessful();

        $liquidacion = Liquidacion::where('reg_medico', 'liq-6')->firstOrFail();

        $this->artisan('liquidaciones:pagar', ['liquidacion' => $liquidacion->id, '--referencia' => 'TRF-9'])
            ->assertSuccessful();

        $this->assertSame(Liquidacion::PAGADA, $liquidacion->fresh()->estado);
    }
}
