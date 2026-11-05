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

    /** Las pestañas de Procedimientos, Indicadores e I.Q. llevan el tilde de «con datos» y las que no tienen pantalla. */
    public function test_las_opciones_pueden_traer_el_tilde_y_estar_pendientes(): void
    {
        $html = Blade::render('<x-ui.chips :opciones="$o" etiqueta="Prueba" />', ['o' => [
            ['texto' => 'Con datos', 'url' => '/a', 'activa' => true, 'cargada' => true],
            ['texto' => 'Vacía', 'url' => '/b', 'activa' => false, 'cargada' => false],
            ['texto' => 'Sin pantalla', 'url' => '/c', 'activa' => false, 'pendiente' => true],
        ]]);

        // El tilde va dentro del enlace de la que tiene datos, y no en la vacía.
        $this->assertSame(1, substr_count($html, 'bi-check2-circle'));
        $this->assertMatchesRegularExpression('/Con datos<i class="bi bi-check2-circle[^>]*><\/i><\/a>/', $html);
        $this->assertMatchesRegularExpression('/Vacía<\/a>/', $html);

        // La pendiente no es un enlace y avisa que llega después.
        $this->assertStringContainsString('chip-opcion chip-opcion-pendiente', $html);
        $this->assertStringContainsString('(próximamente)', $html);
        $this->assertStringNotContainsString('href="/c"', $html);
    }
}
