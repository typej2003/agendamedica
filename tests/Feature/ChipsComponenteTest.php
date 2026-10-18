<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ChipsComponenteTest extends TestCase
{
    public function test_los_chips_marcan_el_elegido_y_no_se_envuelven(): void
    {
        $html = Blade::render('<x-ui.chips :opciones="$o" etiqueta="Prueba" />', ['o' => [
            ['texto' => 'Uno', 'url' => '/a', 'activa' => false],
            ['texto' => 'Dos', 'url' => '/b', 'activa' => true],
        ]]);

        $this->assertStringContainsString('aria-label="Prueba"', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/chip-opcion activo"\s+href="\/b"/', $html);
    }
}
