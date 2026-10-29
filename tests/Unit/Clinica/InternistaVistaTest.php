<?php

namespace Tests\Unit\Clinica;

use App\Actions\Consulta\GuardarDatosDelInternista;
use App\Clinica\Consulta\Interna\MapaDelInternista;
use App\Clinica\Consulta\Vistas\InternistaVista;
use PHPUnit\Framework\TestCase;

/**
 * La vista de las secciones del internista (MI-2 a MI-5) y las cuentas que no necesitan base de datos.
 */
class InternistaVistaTest extends TestCase
{
    private function vista(string $seccion, string $pestana, array $valores = [], array $evolucion = [], array $old = []): InternistaVista
    {
        return new InternistaVista($seccion, $pestana, [
            ['clave' => 'a', 'texto' => 'A', 'url' => '/a', 'activa' => true, 'cargada' => false],
            ['clave' => 'b', 'texto' => 'B', 'url' => '/b', 'activa' => false, 'cargada' => true],
        ], $valores, '/guardar', '/volver', $old, null, $evolucion);
    }

    public function test_los_campos_traen_lo_guardado_o_lo_escrito(): void
    {
        $vm = $this->vista(MapaDelInternista::SECCION_PLAN, 'ekg', ['ekg_ritmo' => 'SINUSAL', 'ekg_bav' => 'S'], [], ['ekg_fc' => '80']);
        $campos = collect($vm->campos())->keyBy('clave');

        $this->assertSame('SINUSAL', $campos['ekg_ritmo']['valor']);
        $this->assertSame('80', $campos['ekg_fc']['valor'], 'lo escrito en un POST que falló manda');
        $this->assertTrue($campos['ekg_bav']['marcado']);
        $this->assertSame('Guardar e.k.g.', $vm->textoGuardar());
        $this->assertTrue($vm->tieneFormulario());
    }

    public function test_las_series_de_la_evolucion_y_sus_puntos(): void
    {
        $vm = $this->vista(MapaDelInternista::SECCION_ANALISIS, '', [], [
            ['nroconsulta' => 1, 'fecha' => '2026-09-01', 'valores' => ['colesterol' => 200.0, 'peso' => null]],
            ['nroconsulta' => 2, 'fecha' => '2026-10-01', 'valores' => ['colesterol' => 180.0, 'peso' => null]],
        ]);

        $series = $vm->series();
        $this->assertCount(1, $series, 'un valor sin datos no se dibuja');
        $this->assertSame('Colesterol', $series[0]['etiqueta']);
        $this->assertSame(['200', '180'], $series[0]['celdas']);
        $this->assertSame('10,10 290,70', $series[0]['puntos'], 'el mayor arriba, el menor abajo');
        $this->assertSame('180 mg/dl', $series[0]['ultimo']);
        $this->assertSame([['nro' => 1, 'fecha' => '01/09/2026'], ['nro' => 2, 'fecha' => '01/10/2026']], $vm->consultasDeLaEvolucion());
        $this->assertFalse($vm->tieneFormulario());
    }

    public function test_el_imc_con_la_talla_en_metros_o_en_centimetros(): void
    {
        $this->assertSame(31.25, GuardarDatosDelInternista::imc(80, 1.6));
        $this->assertSame(31.25, GuardarDatosDelInternista::imc('80', '160'));
        $this->assertNull(GuardarDatosDelInternista::imc(80, null));
    }

    public function test_el_examen_funcional_ofrece_sus_plantillas_y_el_resumen_su_actualizar(): void
    {
        $funcional = new InternistaVista(
            MapaDelInternista::SECCION_FISICO, 'funcional',
            [['clave' => 'funcional', 'texto' => 'Examen funcional', 'url' => '/f', 'activa' => true, 'cargada' => false]],
            ['funcionae' => 'Disnea de esfuerzo.'], '/guardar', '/volver',
            [], null, [], null,
            [(object) ['titulo' => 'NORMAL', 'texto' => 'Sin disnea.']],
        );

        $this->assertTrue($funcional->ofrecePlantillas());
        $this->assertSame('EF', $funcional->tipoDePlantillas());
        $this->assertSame('interna-funcionae', $funcional->campoDePlantillas());
        $this->assertSame([['titulo' => 'NORMAL', 'texto' => 'Sin disnea.']], $funcional->plantillas());        $this->assertFalse($funcional->esResumen());
        $this->assertNull($funcional->urlResumen());

        $resumen = new InternistaVista(
            MapaDelInternista::SECCION_FISICO, 'resumen',
            [['clave' => 'resumen', 'texto' => 'Resumen físico', 'url' => '/r', 'activa' => true, 'cargada' => false]],
            ['resultadoexamencomp' => 'PESO: 80 kg.'], '/guardar', '/volver',
            [], null, [], null, [], '/resumen',
        );

        $this->assertTrue($resumen->esResumen());
        $this->assertSame('/resumen', $resumen->urlResumen());
        $this->assertFalse($resumen->ofrecePlantillas());
    }

    public function test_la_normalizacion_de_cada_tipo(): void
    {
        $numero = ['tipo' => 'numero'];
        $opcion = ['tipo' => 'opcion', 'opciones' => ['A' => 'Uno']];

        $this->assertSame(36.8, GuardarDatosDelInternista::normalizar($numero, '36,8'));
        $this->assertNull(GuardarDatosDelInternista::normalizar($numero, ''));
        $this->assertSame('N', GuardarDatosDelInternista::normalizar(['tipo' => 'si_no'], 'N'));
        $this->assertSame('S', GuardarDatosDelInternista::normalizar(['tipo' => 'si_no'], ['N', 'S']));
        $this->assertSame('A', GuardarDatosDelInternista::normalizar($opcion, 'A'));
        $this->assertNull(GuardarDatosDelInternista::normalizar($opcion, 'Z'));
        $this->assertSame('ab', GuardarDatosDelInternista::normalizar(['tipo' => 'texto', 'max' => 2], 'abc'));
    }
}
