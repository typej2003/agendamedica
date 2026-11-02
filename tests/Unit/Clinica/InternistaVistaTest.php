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

    /**
     * **Cada opción de las cinco escalas trae su texto** (O-47): el mismo que el escritorio escribe en «Descripción».
     * Un valor que no es de la escala, o vacío, devuelve `null`; la escala ASA deja el marcador `{emergencia}` para
     * que lo resuelva quien la pinta.
     */
    public function test_cada_opcion_de_las_escalas_trae_el_texto_del_escritorio(): void
    {
        $escalas = [
            'pre_espiro' => [['A', 'RIESGO BAJO'], ['B', 'RIESGO ELEVADO'], ['C', 'INOPERABILIDAD']],
            'pre_trombo' => [['A', 'BAJO RIESGO'], ['B', 'RIESGO MODERADO'], ['C', 'RIESGO ALTO']],
            'pre_infec' => [['A', 'HERIDAS LIMPIAS'], ['B', 'HERIDAS LIMPIAS CONTAMINADAS'], ['C', 'HERIDAS CONTAMINADAS'], ['D', 'HERIDAS SUCIAS E INFECTADAS']],
            'pre_aneste' => [['A', 'CLASE I'], ['B', 'CLASE II'], ['C', 'CLASE III'], ['D', 'CLASE IV'], ['E', 'CLASE V']],
            'pre_espec' => [['A', 'NEFROPATIAS'], ['B', 'HEPATOPATIAS'], ['C', 'TRASTORNO NEUROLÓGICO'], ['D', 'ENDOCRINOPATIAS'], ['E', 'OTROS']],
        ];

        foreach ($escalas as $escala => $opciones) {
            $textos = MapaDelInternista::textosDeLaEscala($escala);

            $this->assertCount(count($opciones), $textos, $escala);

            foreach ($opciones as [$valor, $titulo]) {
                $texto = MapaDelInternista::textoCompletoDeLaOpcion($escala . '_tipo', $valor);

                $this->assertNotNull($texto, "{$escala} {$valor}");
                $this->assertStringStartsWith($titulo, $texto, "{$escala} {$valor}");
            }
        }

        // La clase ASA interpola la emergencia: el marcador queda en el texto para que lo resuelva la pantalla.
        $this->assertStringContainsString('{emergencia}', (string) MapaDelInternista::textoCompletoDeLaOpcion('pre_aneste_tipo', 'A'));

        // Un valor que no es de la escala y uno vacío no inventan texto.
        $this->assertNull(MapaDelInternista::textoCompletoDeLaOpcion('pre_espiro_tipo', 'Z'));
        $this->assertNull(MapaDelInternista::textoCompletoDeLaOpcion('pre_espiro_tipo', ''));
        $this->assertNull(MapaDelInternista::textoCompletoDeLaOpcion('peso', 'A'));
        $this->assertSame([], MapaDelInternista::textosDeLaEscala('peso'));
    }

    /** La escala de *Estimaciones* le pasa a la pantalla el texto de cada opción y el cuadro al que va. */
    public function test_los_campos_de_la_escala_traen_sus_textos_y_su_descripcion(): void
    {
        $vm = $this->vista(MapaDelInternista::SECCION_PREOPERATORIA, 'estimaciones', ['pre_espiro_tipo' => 'B']);
        $campos = collect($vm->campos())->keyBy('clave');

        $this->assertSame('interna-pre_espiro_resultado', $campos['pre_espiro_tipo']['descripcionDe']);
        $this->assertStringContainsString('FVC < 50%', $campos['pre_espiro_tipo']['textos']['B']);
        $this->assertStringContainsString('EDAD < ó > 40 años', $campos['pre_trombo_tipo']['textos']['A']);
        $this->assertSame([], $campos['peso']['textos'] ?? [], 'un campo que no es escala no lleva textos');
    }
}
