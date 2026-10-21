<?php

namespace Tests\Unit\Pagos;

use App\Pagos\Comisiones;
use App\Pagos\Porcentaje;
use PHPUnit\Framework\TestCase;

/**
 * **La cascada de comisiones** (decisión del usuario, 2026-10-21): la comisión del método pega sobre el
 * bruto y la de cita online sobre el remanente, no sobre el bruto.
 *
 * Con 100,00 USD, 10% de método y 30% de cita online: 10,00 / 90,00 / 27,00 / 63,00.
 */
class ComisionesTest extends TestCase
{
    public function test_la_cascada_del_ejemplo(): void
    {
        $r = Comisiones::calcular(10_000, 1_000, 3_000);

        $this->assertSame(1_000, $r['metodo']);
        $this->assertSame(9_000, $r['base_cita_online']);
        $this->assertSame(2_700, $r['cita_online']);
        $this->assertSame(3_700, $r['total']);
        $this->assertSame(6_300, $r['neto']);

        // Las partes suman el bruto: no se pierde ningún centavo.
        $this->assertSame(10_000, $r['metodo'] + $r['cita_online'] + $r['neto']);
    }

    public function test_sin_comisiones_el_neto_es_el_bruto(): void
    {
        $r = Comisiones::calcular(10_000, 0, 0);

        $this->assertSame(0, $r['total']);
        $this->assertSame(10_000, $r['neto']);
    }

    public function test_el_metodo_al_cien_por_ciento_deja_cero(): void
    {
        $r = Comisiones::calcular(10_000, 10_000, 3_000);

        $this->assertSame(10_000, $r['metodo']);
        $this->assertSame(0, $r['base_cita_online']);
        $this->assertSame(0, $r['cita_online']);
        $this->assertSame(0, $r['neto']);
    }

    public function test_el_redondeo_es_al_centavo_y_el_neto_absorbe_la_diferencia(): void
    {
        // 0,01 USD al 50% = 0,005 → 1 centavo (half-up).
        $r = Comisiones::calcular(1, 5_000, 0);

        $this->assertSame(1, $r['metodo']);
        $this->assertSame(0, $r['neto']);
    }

    public function test_un_porcentaje_invalido_no_se_acepta(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Porcentaje::aplicar(10_000, 10_001);
    }
}
