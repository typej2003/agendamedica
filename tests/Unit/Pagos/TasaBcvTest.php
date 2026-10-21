<?php

namespace Tests\Unit\Pagos;

use App\Pagos\Tasas\TasaBcv;
use PHPUnit\Framework\TestCase;

/**
 * **La lectura de la tasa del BCV** (2026-10-21): el bloque `#dolar` de la página del Banco Central, como
 * en el `BvcScrappingExtService` de DonDemand.
 *
 * Se prueba el parser sin red: formato venezolano (`36,1234`), espacios duros y valores inválidos.
 */
class TasaBcvTest extends TestCase
{
    private function servicio(): TasaBcv
    {
        return new TasaBcv();
    }

    public function test_lee_el_dolar_del_bloque_del_bcv(): void
    {
        $html = '<html><body><div id="dolar"><strong>36,1234</strong></div></body></html>';

        $this->assertSame('36.123400', $this->servicio()->parsear($html));
    }

    public function test_acepta_espacios_duros_y_el_formato_venezolano(): void
    {
        $html = "<html><body><div id=\"dolar\"><strong>1.234,56&nbsp;</strong></div></body></html>";

        $this->assertSame('1234.560000', $this->servicio()->parsear($html));
    }

    public function test_sin_el_bloque_del_dolar_falla(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->servicio()->parsear('<html><body><p>Sin tasa</p></body></html>');
    }

    public function test_un_valor_que_no_es_numero_falla(): void
    {
        $html = '<html><body><div id="dolar"><strong>sin dato</strong></div></body></html>';

        $this->expectException(\RuntimeException::class);

        $this->servicio()->parsear($html);
    }
}
