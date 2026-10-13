<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\EnfermedadActualVista;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de la **enfermedad actual de una consulta** (WEB-4.2) se prueba sin base de datos ni HTTP.
 *
 * Lo que se fija acá: que el texto que ya escribió el escritorio se vea **tal cual**, que el valor del
 * `<textarea>` sea el que se acaba de escribir cuando el POST falló (no se borra lo escrito) y que el
 * estado diga si la consulta todavía no tiene nada anotado.
 */
class EnfermedadActualVistaTest extends TestCase
{
    private function vista(?string $texto = null): EnfermedadActualVista
    {
        return new EnfermedadActualVista(
            $texto,
            '/clinica/consulta/42/enfermedad',
            '/clinica/consulta/42?seccion=enfermedad',
        );
    }

    public function test_el_texto_del_escritorio_se_ve_tal_cual(): void
    {
        // Con saltos de línea y todo: es el mismo dato, no hay copia ni reformateo.
        $texto = "Paciente refiere dolor pélvico de 3 días.\nNiega fiebre.\n\nAntecedente: cesárea 2019.";
        $vm = $this->vista($texto);

        $this->assertSame($texto, $vm->texto());
        $this->assertSame($texto, $vm->valor());
        $this->assertFalse($vm->estaVacia());
    }

    public function test_una_consulta_sin_enfermedad_actual_lo_dice_y_no_inventa_texto(): void
    {
        $vm = $this->vista(null);

        $this->assertNull($vm->texto());
        $this->assertSame('', $vm->valor());
        $this->assertTrue($vm->estaVacia());
        $this->assertSame('Sin escribir', $vm->estado()['texto']);
        $this->assertSame('neutro', $vm->estado()['tono']);
    }

    public function test_el_texto_vacio_o_en_blanco_cuenta_como_sin_escribir(): void
    {
        $this->assertTrue($this->vista('   ')->estaVacia());
        $this->assertSame('Sin escribir', $this->vista('')->estado()['texto']);
    }

    public function test_con_texto_el_estado_dice_que_esta_escrita(): void
    {
        $estado = $this->vista('Dolor de 3 días.')->estado();

        $this->assertSame('Escrita', $estado['texto']);
        $this->assertSame('exito', $estado['tono']);
    }

    public function test_lo_escrito_manda_sobre_lo_guardado_cuando_el_post_fallo(): void
    {
        // Después de un error de validación la pantalla muestra lo que se escribió (el `old()`), no lo
        // que estaba guardado: no se borra lo escrito (guía de UI §3).
        $vm = $this->vista('Lo que estaba guardado');

        $this->assertSame('Lo que se acaba de escribir', $vm->valor('Lo que se acaba de escribir'));
    }

    public function test_la_ayuda_dice_que_se_guarda_con_el_boton_y_que_el_navegador_avisa_al_salir(): void
    {
        $ayuda = $this->vista()->ayuda();

        $this->assertStringContainsString('botón', $ayuda);
        $this->assertStringContainsString('avisa', $ayuda);
        $this->assertNotSame('', $this->vista()->marcador());
    }

    public function test_las_urls_las_resuelve_el_controlador(): void
    {
        $vm = $this->vista();

        $this->assertSame('/clinica/consulta/42/enfermedad', $vm->urlGuardar());
        $this->assertSame('/clinica/consulta/42?seccion=enfermedad', $vm->urlVolver());
    }
}
