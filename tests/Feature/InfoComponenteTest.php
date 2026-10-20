<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** El «i» de `<x-ui.info>`: botón accesible y texto oculto hasta el clic (lo abre `shell.js`). */
class InfoComponenteTest extends TestCase
{
    public function test_el_info_pinta_el_boton_y_el_texto_oculto(): void
    {
        $html = Blade::render('<x-ui.info>FUR + 280 días.</x-ui.info>');

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('aria-label="Más información"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('bi-info-circle', $html);
        $this->assertMatchesRegularExpression('/role="note"\s+hidden>FUR \+ 280 días\.</', $html);
    }

    public function test_el_campo_con_info_lo_pinta_junto_a_la_etiqueta(): void
    {
        view()->share('errors', new ViewErrorBag());
        $html = Blade::render('<x-ui.campo etiqueta="FPP" for="fpp" info="Calculada desde la FUR."><input id="fpp"></x-ui.campo>');

        $this->assertMatchesRegularExpression('/<label[^>]*>FPP<\/label>\s*<span class="info-pista/', $html);
        $this->assertStringContainsString('Calculada desde la FUR.', $html);
    }

    public function test_el_campo_sin_info_no_pinta_el_boton(): void
    {
        view()->share('errors', new ViewErrorBag());
        $html = Blade::render('<x-ui.campo etiqueta="FPP" for="fpp"><input id="fpp"></x-ui.campo>');

        $this->assertStringNotContainsString('data-info', $html);
    }
}
