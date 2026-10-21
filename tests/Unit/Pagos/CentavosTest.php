<?php

namespace Tests\Unit\Pagos;

use App\Pagos\Centavos;
use App\Pagos\Tasa;
use PHPUnit\Framework\TestCase;

/**
 * **El dinero en centavos** (2026-10-21): la conversión desde el texto del formulario y la tasa como factor.
 *
 * Lo que se fija: no se pasa por `float`, se acepta el formato venezolano (`1.234,56`) y se redondea al
 * centavo (half-up) en vez de truncar. La tasa se opera con un entero escalado a 6 decimales.
 */
class CentavosTest extends TestCase
{
    public function test_convierte_los_formatos_que_usa_el_formulario(): void
    {
        $this->assertSame(1999, Centavos::deDecimal('19.99'));
        $this->assertSame(1999, Centavos::deDecimal('19,99'));
        $this->assertSame(123456, Centavos::deDecimal('1.234,56'));
        $this->assertSame(2000, Centavos::deDecimal('20'));
        $this->assertSame(0, Centavos::deDecimal(null));
        $this->assertSame(0, Centavos::deDecimal(''));
        $this->assertSame(-510, Centavos::deDecimal('-5,10'));
    }

    public function test_redondea_half_up_al_centavo_en_vez_de_truncar(): void
    {
        $this->assertSame(2000, Centavos::deDecimal('19.999'));
        $this->assertSame(1999, Centavos::deDecimal('19.994'));
        $this->assertSame(2000, Centavos::deDecimal('19.995')); // 19,995 → 20,00
    }

    public function test_vuelve_al_texto_decimal_con_dos_decimales(): void
    {
        $this->assertSame('19.99', Centavos::aDecimal(1999));
        $this->assertSame('0.05', Centavos::aDecimal(5));
        $this->assertSame('-5.10', Centavos::aDecimal(-510));
        $this->assertSame('19,99', Centavos::aTexto(1999));
    }

    public function test_la_tasa_se_escala_a_seis_decimales_y_convierte(): void
    {
        $this->assertSame(36_123_456, Tasa::aEscala('36,123456'));
        $this->assertSame('36.123456', Tasa::desdeEscala(36_123_456));

        // 100,00 USD a 36,5 = 3.650,00 VES.
        $this->assertSame(365_000, Tasa::usdAVes(10_000, '36,5'));
        // Y de vuelta.
        $this->assertSame(10_000, Tasa::vesAUsd(365_000, '36,5'));
    }

    public function test_una_tasa_cero_no_divide(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Tasa::usdAVes(10_000, '0');
    }
}
