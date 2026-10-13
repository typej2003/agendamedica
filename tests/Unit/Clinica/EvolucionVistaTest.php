<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\EvolucionVista;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de la **sección de evolución de una consulta** (WEB-4.6, ítem 9 del panel) se prueba sin base
 * de datos ni HTTP: el texto ya leído y las plantillas del médico → textos, estados y URLs
 * (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá es lo que la pantalla decide: el estado vacío, el texto del legado **tal cual** (con
 * sus saltos de línea y su `\r\n`), los "vacíos" del legado —incluido el literal `none`— leídos como "sin
 * texto", y las plantillas: cuántas hay, cómo llegan a la vista y qué se dice cuando no hay ninguna.
 */
class EvolucionVistaTest extends TestCase
{
    /** @param array<int,array{0:string,1:string,2:string}> $plantillas código, título y texto */
    private function plantillas(array $plantillas): Collection
    {
        return collect($plantillas)->map(fn (array $plantilla) => (object) [
            'codigo' => $plantilla[0],
            'titulo' => $plantilla[1],
            'texto' => $plantilla[2],
        ]);
    }

    private function vista(array $extra = []): EvolucionVista
    {
        return new EvolucionVista(
            array_key_exists('texto', $extra) ? $extra['texto'] : null,
            $extra['plantillas'] ?? $this->plantillas([]),
            $extra['urlGuardar'] ?? '/clinica/consulta/42/evolucion',
            $extra['urlVolver'] ?? '/clinica/consulta/42?seccion=evolucion',
        );
    }

    public function test_una_consulta_sin_evolucion_lo_dice(): void
    {
        $vm = $this->vista();

        $this->assertTrue($vm->estaVacia());
        $this->assertNull($vm->texto());
        $this->assertSame('', $vm->valor());
        $this->assertSame('Sin escribir', $vm->estado()['texto']);
        $this->assertSame('neutro', $vm->estado()['tono']);
        $this->assertSame('bi-dash-circle', $vm->estado()['icono']);
    }

    public function test_el_texto_del_escritorio_llega_tal_cual_con_sus_saltos_de_linea(): void
    {
        // El legado guarda el párrafo completo, con `\r\n` incluido: la evolución de la web es el **mismo
        // dato**, no una copia reformateada. El ViewModel no lo toca.
        $texto = "UC AGOSTO DEL 2007 FUR 13-10-08\r\nSE LE INDICARON EXAMENES DE LABORATORIO\r\nSE LE ENTREGO CITO";

        $vm = $this->vista(['texto' => $texto]);

        $this->assertFalse($vm->estaVacia());
        $this->assertSame($texto, $vm->texto());
        $this->assertSame($texto, $vm->valor());
        $this->assertSame('Escrita', $vm->estado()['texto']);
        $this->assertSame('exito', $vm->estado()['tono']);
    }

    public function test_un_texto_de_solo_espacios_es_una_consulta_sin_evolucion(): void
    {
        // El literal `none` y los espacios del legado **no llegan acá**: los limpia la lectura
        // (`EvolucionDeLaConsulta` / `TextoDeLaConsulta`). Lo que sí se prueba es que la sección no diga
        // "Escrita" por un texto en blanco.
        $this->assertTrue($this->vista(['texto' => '   '])->estaVacia());
    }

    public function test_el_post_que_fallo_la_validacion_no_borra_lo_escrito(): void
    {
        $vm = $this->vista(['texto' => 'lo guardado']);

        $this->assertSame('lo que el médico escribió', $vm->valor('lo que el médico escribió'));
        // Sin `old()` (el caso normal) el campo muestra el dato guardado.
        $this->assertSame('lo guardado', $vm->valor(''));
    }

    public function test_las_plantillas_del_escritorio_llegan_con_su_titulo_y_su_texto(): void
    {
        $vm = $this->vista(['plantillas' => $this->plantillas([
            ['0001', 'CONTROL POST-OPERATORIO', 'Control post-operatorio: herida sin signos de infección.'],
            ['0002', 'ALTA', 'Paciente de alta con indicaciones.'],
        ])]);

        $this->assertTrue($vm->hayPlantillas());
        $this->assertSame(2, $vm->totalPlantillas());

        $plantillas = $vm->plantillas();
        $this->assertSame('0001', $plantillas[0]['codigo']);
        $this->assertSame('CONTROL POST-OPERATORIO', $plantillas[0]['titulo']);
        // El texto es el que el script copia al campo: la `descripcion` de `formato_print`.
        $this->assertSame('Control post-operatorio: herida sin signos de infección.', $plantillas[0]['texto']);
    }

    public function test_un_medico_sin_plantillas_tiene_su_estado_vacio(): void
    {
        $vm = $this->vista();

        $this->assertFalse($vm->hayPlantillas());
        $this->assertSame(0, $vm->totalPlantillas());
        $this->assertSame([], $vm->plantillas());

        // La frase dice **por qué** no hay y qué hacer: es el estado real de casi todas las instalaciones
        // (desde WEB-8.4 se crean en Catálogos → Plantillas de texto; antes sólo llegaban por el sync del escritorio).
        $vacio = $vm->sinPlantillas();
        $this->assertSame('Sin plantillas de evolución', $vacio['titulo']);
        $this->assertStringContainsString('Plantillas de texto', $vacio['texto']);
        $this->assertStringContainsString('tipo Evolución', $vacio['texto']);
    }

    public function test_las_urls_son_las_de_la_seccion_y_el_aviso_nombra_la_evolucion(): void
    {
        $vm = $this->vista();

        $this->assertSame('/clinica/consulta/42/evolucion', $vm->urlGuardar());
        $this->assertSame('/clinica/consulta/42?seccion=evolucion', $vm->urlVolver());
        $this->assertStringContainsString('evolución', $vm->avisoSinGuardar());
        $this->assertStringContainsString('evolución', strtolower($vm->ayuda()));
        $this->assertNotSame('', $vm->marcador());
    }
}
