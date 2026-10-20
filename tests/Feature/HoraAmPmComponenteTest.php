<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * **`<x-ui.hora>`**: un campo de texto con AM/PM para lo que ve la persona y un `hidden` con `HH:MM` de 24 h para
 * lo que recibe el servidor (que no cambió). La normalización del texto la hace `public/js/clinica/hora-ampm.js`
 * y se prueba con `node tests/js/hora-ampm.test.js`.
 */
class HoraAmPmComponenteTest extends TestCase
{
    public function test_pinta_el_campo_visible_en_12_horas_y_el_hidden_en_24(): void
    {
        $html = Blade::render('<x-ui.hora name="hora_fin" valor="13:00:00" required aria-label="Hasta qué hora" />');

        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('id="hora_fin"', $html);
        $this->assertStringContainsString('value="1:00 PM"', $html);
        $this->assertStringContainsString('placeholder="8:00 AM"', $html);
        $this->assertStringContainsString('data-hora-ampm="hora_fin-valor"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('aria-label="Hasta qué hora"', $html);
        // El nombre lo lleva sólo el hidden, con la hora de 24 h que ya validaba el servidor.
        $this->assertStringContainsString('<input type="hidden" name="hora_fin" id="hora_fin-valor" value="13:00">', $html);
        $this->assertSame(1, substr_count($html, 'name="hora_fin"'));
    }

    public function test_sin_valor_deja_los_dos_campos_vacios(): void
    {
        $html = Blade::render('<x-ui.hora name="hora" :id="\'bloque-desde-4\'" />');

        $this->assertStringContainsString('id="bloque-desde-4"', $html);
        $this->assertStringContainsString('value=""', $html);
        $this->assertStringContainsString('name="hora" id="bloque-desde-4-valor" value=""', $html);
        $this->assertStringNotContainsString('required', $html);
    }

    public function test_un_valor_invalido_se_ve_tal_cual_y_no_viaja(): void
    {
        $html = Blade::render('<x-ui.hora name="hora" valor="mañana" />');

        $this->assertStringContainsString('value="mañana"', $html);
        $this->assertStringContainsString('name="hora" id="hora-valor" value=""', $html);
    }

    public function test_marca_el_campo_cuando_hay_error_de_validacion(): void
    {
        $errores = new \Illuminate\Support\ViewErrorBag();
        $errores->put('default', new \Illuminate\Support\MessageBag(['hora' => ['mal']]));
        view()->share('errors', $errores);

        $html = Blade::render('<x-ui.hora name="hora" valor="08:00" />');

        $this->assertStringContainsString('is-invalid', $html);
    }

    public function test_el_armazon_carga_el_script(): void
    {
        $this->assertFileExists(public_path('js/clinica/hora-ampm.js'));
        $this->assertStringContainsString('hora-ampm.js', file_get_contents(resource_path('views/clinica/layout.blade.php')));
    }
}
