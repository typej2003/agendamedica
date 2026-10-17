<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Listados\FiltroDeConsultas;
use App\Clinica\Listados\ListadosDisponibles;
use App\Clinica\Listados\Vistas\ListadoDeConsultasVista;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * **El listado de consultas por rango** (WEB-6.8, los *Indicadores de Consultas* de `w_reporte_consulta_barbaro.srw`),
 * sin base de datos: los filtros normalizados —el rango por **fecha de la consulta** con los defaults del escritorio,
 * la edad, y los cuatro textos— y las filas ya formateadas del ViewModel.
 */
class ListadoDeConsultasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hoy(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', '2026-10-14');
    }

    private function consulta(array $cambios = []): object
    {
        return (object) array_merge([
            'consulta_id'  => 1,
            'numhistoria'  => 12,
            'nroconsulta'  => 3,
            'fecha'        => '2026-09-20',
            'paciente_id'  => 7,
            'nombres'      => 'Ana María',
            'apellidos'    => 'Pérez',
            'fnacimiento'  => '1992-10-14',
            'motivos'      => ['DOLOR PÉLVICO'],
            'diagnosticos' => ['QUISTE DE OVARIO', 'ANEMIA LEVE'],
            'medicamentos' => ['IBUPROFENO 400 MG'],
            'antecedentes' => [],
        ], $cambios);
    }

    private function vista(array $consultas, array $entrada = []): ListadoDeConsultasVista
    {
        return new ListadoDeConsultasVista(
            collect($consultas),
            FiltroDeConsultas::de($entrada, $this->hoy()),
            '/clinica/listados?listado=consultas-por-rango',
            '/clinica/listados/imprimir',
            fn (int $id) => '/clinica/pacientes/' . $id,
        );
    }

    // ------------------------------------------------------------------------------------ Los filtros

    public function test_los_valores_por_defecto_son_los_del_escritorio_y_no_hay_nada_que_limpiar(): void
    {
        $filtro = FiltroDeConsultas::de([], $this->hoy());

        $this->assertSame('2000-01-01', $filtro->rangoDeConsulta()->desde());
        $this->assertSame('2026-10-14', $filtro->rangoDeConsulta()->hasta());
        $this->assertSame([1, 110], [$filtro->edadInicial(), $filtro->edadFinal()]);
        $this->assertNull($filtro->motivo());
        $this->assertNull($filtro->diagnostico());
        $this->assertNull($filtro->medicamento());
        $this->assertNull($filtro->antecedente());
        $this->assertFalse($filtro->hayFiltro());
    }

    public function test_cualquier_filtro_puesto_se_puede_limpiar(): void
    {
        foreach ([
            ['desde' => '2024-01-01'], ['hasta' => '2026-01-01'], ['edad_inicial' => '18'], ['edad_final' => '65'],
            ['motivo' => 'dolor'], ['diagnostico' => 'quiste'], ['medicamento' => 'hierro'], ['antecedente' => 'diabetes'],
        ] as $entrada) {
            $this->assertTrue(FiltroDeConsultas::de($entrada, $this->hoy())->hayFiltro(), json_encode($entrada));
        }
    }

    public function test_los_textos_se_recortan_y_el_vacio_no_filtra(): void
    {
        $filtro = FiltroDeConsultas::de(['motivo' => '   ', 'diagnostico' => '  quiste  ', 'medicamento' => str_repeat('a', 200)], $this->hoy());

        $this->assertNull($filtro->motivo());
        $this->assertSame('quiste', $filtro->diagnostico());
        $this->assertSame(FiltroDeConsultas::LARGO_MAXIMO_DEL_TEXTO, mb_strlen($filtro->medicamento()));
    }

    public function test_las_fechas_y_las_edades_al_reves_se_dan_vuelta_y_se_avisa(): void
    {
        $filtro = FiltroDeConsultas::de(['desde' => '2026-06-30', 'hasta' => '2026-06-01', 'edad_inicial' => '50', 'edad_final' => '20'], $this->hoy());

        $this->assertSame(['2026-06-01', '2026-06-30'], [$filtro->rangoDeConsulta()->desde(), $filtro->rangoDeConsulta()->hasta()]);
        $this->assertSame([20, 50], [$filtro->edadInicial(), $filtro->edadFinal()]);
        $this->assertTrue($filtro->seVoltearonLasFechas());
        $this->assertTrue($filtro->seVoltearonLasEdades());
    }

    public function test_el_listado_esta_en_el_catalogo(): void
    {
        $this->assertTrue(ListadosDisponibles::existe(ListadosDisponibles::CONSULTAS));
        $this->assertSame('Consultas por rango', ListadosDisponibles::titulo(ListadosDisponibles::CONSULTAS));
    }

    // ------------------------------------------------------------------------------------ El ViewModel

    public function test_las_filas_salen_formateadas_y_con_sus_listas(): void
    {
        $fila = $this->vista([$this->consulta()])->filas()[0];

        $this->assertSame('00012', $fila['historia']);
        $this->assertSame('Pérez, Ana María', $fila['nombre']);
        $this->assertSame('34 años', $fila['edad']);
        $this->assertSame('20/09/2026', $fila['fecha']);
        $this->assertSame(['DOLOR PÉLVICO'], $fila['motivos']);
        $this->assertSame(['QUISTE DE OVARIO', 'ANEMIA LEVE'], $fila['diagnosticos']);
        $this->assertSame(['IBUPROFENO 400 MG'], $fila['medicamentos']);
        $this->assertSame('/clinica/pacientes/7', $fila['url']);
    }

    public function test_lo_que_falta_no_se_inventa(): void
    {
        $fila = $this->vista([$this->consulta(['numhistoria' => null, 'fnacimiento' => null, 'motivos' => [], 'diagnosticos' => [], 'medicamentos' => []])])->filas()[0];

        $this->assertNull($fila['historia']);
        $this->assertNull($fila['edad']);
        $this->assertSame([[], [], []], [$fila['motivos'], $fila['diagnosticos'], $fila['medicamentos']]);
    }

    public function test_la_columna_de_antecedente_solo_esta_si_se_filtro_por_uno(): void
    {
        $this->assertSame(['motivos', 'diagnosticos', 'medicamentos'], array_keys($this->vista([])->columnasDeListas()));
        $this->assertFalse($this->vista([])->muestraAntecedentes());

        $con = $this->vista([], ['antecedente' => 'diabetes']);
        $this->assertTrue($con->muestraAntecedentes());
        $this->assertSame(['motivos', 'antecedentes', 'diagnosticos', 'medicamentos'], array_keys($con->columnasDeListas()));
    }

    public function test_el_subtitulo_cuenta_las_consultas_y_dice_los_filtros(): void
    {
        $vm = $this->vista([$this->consulta(), $this->consulta()], ['desde' => '2024-01-01', 'hasta' => '2024-12-31', 'diagnostico' => 'quiste']);

        $this->assertSame('2 consultas · del 01/01/2024 al 31/12/2024 · de 1 a 110 años · diagnóstico: quiste.', $vm->subtitulo());
        $this->assertStringStartsWith('1 consulta ·', $this->vista([$this->consulta()])->subtitulo());
    }

    public function test_el_estado_vacio_distingue_la_busqueda_sin_resultados_del_consultorio_sin_consultas(): void
    {
        $this->assertSame('Todavía no hay consultas', $this->vista([])->vacio()['titulo']);
        $this->assertSame('Ninguna consulta con esos filtros', $this->vista([], ['motivo' => 'x'])->vacio()['titulo']);
    }

    public function test_la_cabecera_impresa_lleva_el_rango_la_edad_y_los_filtros_puestos(): void
    {
        $this->assertSame(['Desde', 'Hasta', 'Edad'], array_column($this->vista([])->descripcionDeLosFiltros(), 'etiqueta'));

        $con = $this->vista([], ['motivo' => 'dolor', 'diagnostico' => 'quiste', 'medicamento' => 'hierro', 'antecedente' => 'diabetes'])->descripcionDeLosFiltros();

        $this->assertSame(['Desde', 'Hasta', 'Edad', 'Motivo', 'Diagnóstico', 'Medicamento', 'Antecedente'], array_column($con, 'etiqueta'));
        $this->assertSame('diabetes', end($con)['valor']);
    }

    public function test_los_valores_del_formulario_vuelven_tal_como_estan(): void
    {
        $vm = $this->vista([], ['desde' => '2024-02-03', 'hasta' => '2025-04-05', 'motivo' => 'dolor']);

        $this->assertSame(['2024-02-03', '2025-04-05', 'dolor', ''], [$vm->desde(), $vm->hasta(), $vm->motivo(), $vm->diagnostico()]);
        $this->assertSame('consultas-por-rango', $vm->claveDelListado());
    }
}
