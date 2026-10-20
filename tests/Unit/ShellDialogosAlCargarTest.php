<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * El armazón sólo abre solo el diálogo guiado de la puesta en marcha. Las pantallas marcan sus diálogos con
 * `data-abrir-al-cargar="0|1"` y los abre su propio script: con un selector que matchee cualquier
 * `dialog[data-abrir-al-cargar]`, «Nueva secretaria» y «Nuevo medicamento» se abrían al entrar (2026-10-20).
 */
class ShellDialogosAlCargarTest extends TestCase
{
    public function test_el_shell_solo_abre_el_dialogo_guiado(): void
    {
        $js = file_get_contents(dirname(__DIR__, 2) . '/public/js/clinica/shell.js');

        $this->assertStringContainsString("dialog#dialogo-paso-esencial[data-abrir-al-cargar]", $js);
        $this->assertStringNotContainsString("querySelector('dialog[data-abrir-al-cargar]')", $js);
    }
}
