<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Listados\FiltroDePacientesPorRango;
use App\Clinica\Listados\ListadosDisponibles;
use App\Clinica\Listados\RangoDeEdades;
use App\Clinica\Listados\Vistas\ListadoDePacientesVista;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * **El listado de pacientes por rango** (WEB-6.8, los *Indicadores pacientes* de `w_reprotes_pacientes.srw`),
 * sin base de datos: los filtros normalizados —el rango de ingreso con los valores por defecto del escritorio,
 * la edad traducida a fechas de nacimiento, el sexo, el estado civil y la procedencia— y las filas ya
 * formateadas del ViewModel.
 */
class ListadoDePacientesTest extends TestCase
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

    private function paciente(array $cambios = []): object
    {
        return (object) array_merge([
            'paciente_id' => 7,
            'nombres'     => 'Ana María',
            'apellidos'   => 'Pérez',
            'nac'         => 'V',
            'cedula'      => '12345678',
            'fnacimiento' => '1992-10-14',
            'codeestado'  => 'C',
            'sexo'        => 'F',
            'fingreso'    => '2020-03-12',
            'codesegemp'  => '02',
            'numhistoria' => '12',
        ], $cambios);
    }

    // ------------------------------------------------------------------------------------- Las edades

    public function test_las_edades_nacen_en_1_y_110_como_el_primer_listado(): void
    {
        $edades = RangoDeEdades::de(null, null);

        $this->assertSame(1, $edades->inicial());
        $this->assertSame(110, $edades->final());
        $this->assertTrue($edades->sonLasDePorDefecto());
        $this->assertFalse($edades->seVoltearon());
    }

    public function test_las_edades_al_reves_se_dan_vuelta_y_la_basura_vuelve_al_valor_por_defecto(): void
    {
        $alReves = RangoDeEdades::de('40', '30');
        $this->assertSame([30, 40], [$alReves->inicial(), $alReves->final()]);
        $this->assertTrue($alReves->seVoltearon());

        $this->assertSame(110, RangoDeEdades::de('abc', 'x1')->final());
        $this->assertSame(1, RangoDeEdades::de('', '')->inicial());
        // Un valor absurdo se recorta al techo.
        $this->assertSame(120, RangoDeEdades::de('1', '999')->final());
    }

    public function test_la_edad_se_traduce_en_fechas_de_nacimiento(): void
    {
        // Entre 30 y 40 años cumplidos hoy (14/10/2026): nacidos entre el 15/10/1985 y el 14/10/1996.
        $nacimiento = RangoDeEdades::de('30', '40')->rangoDeNacimiento($this->hoy());

        $this->assertSame('1985-10-15', $nacimiento->desde());
        $this->assertSame('1996-10-14', $nacimiento->hasta());
    }

    // ------------------------------------------------------------------------------------ Los filtros

    public function test_los_valores_por_defecto_son_los_del_escritorio_y_no_hay_nada_que_limpiar(): void
    {
        $filtro = FiltroDePacientesPorRango::de([], $this->hoy());

        // `em_3.text = '01/01/2000'` y `em_4.text = today()`.
        $this->assertSame('2000-01-01', $filtro->rangoDeIngreso()->desde());
        $this->assertSame('2026-10-14', $filtro->rangoDeIngreso()->hasta());
        $this->assertSame([1, 110], [$filtro->edadInicial(), $filtro->edadFinal()]);
        $this->assertNull($filtro->sexo());
        $this->assertNull($filtro->estadoCivil());
        $this->assertNull($filtro->procedencia());
        $this->assertNull($filtro->codigoDeProcedencia());
        $this->assertFalse($filtro->hayFiltro());
    }

    public function test_cualquier_filtro_puesto_se_puede_limpiar(): void
    {
        foreach ([
            ['desde' => '2024-01-01'],
            ['hasta' => '2026-01-01'],
            ['edad_inicial' => '18'],
            ['edad_final' => '65'],
            ['sexo' => 'F'],
            ['estado_civil' => 'S'],
            ['procedencia' => 'personal'],
        ] as $entrada) {
            $this->assertTrue(FiltroDePacientesPorRango::de($entrada, $this->hoy())->hayFiltro(), json_encode($entrada));
        }
    }

    public function test_el_sexo_el_estado_civil_y_la_procedencia_que_no_se_entienden_no_filtran(): void
    {
        $filtro = FiltroDePacientesPorRango::de(
            ['sexo' => 'X', 'estado_civil' => 'Z', 'procedencia' => 'todos'],
            $this->hoy(),
        );

        $this->assertNull($filtro->sexo());
        $this->assertNull($filtro->estadoCivil());
        $this->assertNull($filtro->procedencia());
        $this->assertFalse($filtro->hayFiltro());

        // En minúscula sí se entienden: son los mismos códigos del legado.
        $this->assertSame('M', FiltroDePacientesPorRango::de(['sexo' => 'm'], $this->hoy())->sexo());
        $this->assertSame('V', FiltroDePacientesPorRango::de(['estado_civil' => 'v'], $this->hoy())->estadoCivil());
    }

    public function test_personal_es_el_codigo_00_y_una_empresa_es_su_propio_codigo(): void
    {
        $personal = FiltroDePacientesPorRango::de(['procedencia' => 'personal'], $this->hoy());
        $this->assertSame('00', $personal->codigoDeProcedencia());

        $empresa = FiltroDePacientesPorRango::de(['procedencia' => '02'], $this->hoy());
        $this->assertSame('02', $empresa->codigoDeProcedencia());
    }

    public function test_las_fechas_de_ingreso_al_reves_se_dan_vuelta_y_se_avisa(): void
    {
        $filtro = FiltroDePacientesPorRango::de(['desde' => '2026-06-30', 'hasta' => '2026-06-01'], $this->hoy());

        $this->assertSame('2026-06-01', $filtro->rangoDeIngreso()->desde());
        $this->assertSame('2026-06-30', $filtro->rangoDeIngreso()->hasta());
        $this->assertTrue($filtro->seVoltearonLasFechas());
    }

    // ------------------------------------------------------------------------------------ El catálogo

    public function test_el_listado_de_pacientes_esta_en_el_catalogo(): void
    {
        $this->assertTrue(ListadosDisponibles::existe(ListadosDisponibles::PACIENTES));
        $this->assertSame('Pacientes por rango', ListadosDisponibles::titulo(ListadosDisponibles::PACIENTES));
    }

    // ------------------------------------------------------------------------------------ El ViewModel

    private function vista(array $pacientes, array $entrada = [], array $seguros = []): ListadoDePacientesVista
    {
        return new ListadoDePacientesVista(
            collect($pacientes),
            FiltroDePacientesPorRango::de($entrada, $this->hoy()),
            collect($seguros),
            '/clinica/listados?listado=pacientes-por-rango',
            '/clinica/listados/imprimir',
            fn (int $id) => '/clinica/pacientes/' . $id,
        );
    }

    public function test_las_filas_salen_con_las_ocho_columnas_formateadas(): void
    {
        $vm = $this->vista(
            [$this->paciente()],
            [],
            [(object) ['codesegemp' => '02', 'nombre' => 'PDVSA']],
        );

        $fila = $vm->filas()[0];

        $this->assertSame('00012', $fila['historia']);          // # Hist., con los ceros del legado
        $this->assertSame('Pérez, Ana María', $fila['nombre']); // Apellidos, Nombres, como toda la web
        $this->assertSame('V-12345678', $fila['cedula']);
        $this->assertSame('34 años', $fila['edad']);
        $this->assertSame('Casado(a)', $fila['ecivil']);
        $this->assertSame('Femenino', $fila['sexo']);
        $this->assertSame('12/03/2020', $fila['fingreso']);
        $this->assertSame('PDVSA', $fila['empresa']);
        $this->assertSame('/clinica/pacientes/7', $fila['url']);
    }

    public function test_lo_que_falta_no_se_inventa(): void
    {
        $vm = $this->vista([$this->paciente([
            'numhistoria' => null,
            'cedula'      => 'S.C.',          // el marcador de «sin cédula» del legado
            'fnacimiento' => null,
            'codeestado'  => null,
            'sexo'        => null,
            'codesegemp'  => '00',            // PERSONAL
        ])]);

        $fila = $vm->filas()[0];

        $this->assertNull($fila['historia']);
        $this->assertNull($fila['cedula']);
        $this->assertNull($fila['edad']);
        $this->assertNull($fila['ecivil']);
        $this->assertNull($fila['sexo']);
        $this->assertNull($fila['empresa']);
    }

    public function test_un_codigo_que_no_esta_en_el_catalogo_se_muestra_tal_cual(): void
    {
        $fila = $this->vista([$this->paciente(['codesegemp' => '77', 'codeestado' => 'Q', 'numhistoria' => 'AB-1'])])->filas()[0];

        $this->assertSame('77', $fila['empresa']);
        $this->assertSame('Q', $fila['ecivil']);
        $this->assertSame('AB-1', $fila['historia']);
    }

    public function test_el_subtitulo_cuenta_los_pacientes_y_dice_los_filtros(): void
    {
        $vm = $this->vista(
            [$this->paciente(), $this->paciente(['paciente_id' => 8])],
            ['sexo' => 'F', 'desde' => '2024-01-01', 'hasta' => '2024-12-31'],
        );

        $this->assertSame(
            '2 pacientes · ingresados entre el 01/01/2024 y el 31/12/2024 · de 1 a 110 años · Femenino.',
            $vm->subtitulo(),
        );
        $this->assertSame('1 paciente', explode(' · ', $this->vista([$this->paciente()])->subtitulo())[0]);

        $conEmpresa = $this->vista([], ['procedencia' => '02'], [(object) ['codesegemp' => '02', 'nombre' => 'PDVSA']]);
        $this->assertStringContainsString(' · empresa: PDVSA.', $conEmpresa->subtitulo());
    }

    public function test_el_estado_vacio_distingue_la_busqueda_sin_resultados_del_consultorio_sin_pacientes(): void
    {
        $this->assertSame('Todavía no hay pacientes', $this->vista([])->vacio()['titulo']);
        $this->assertSame('Ningún paciente con esos filtros', $this->vista([], ['sexo' => 'M'])->vacio()['titulo']);
    }

    public function test_el_aviso_dice_lo_que_se_corrigio(): void
    {
        $this->assertNull($this->vista([])->aviso());

        $aviso = $this->vista([], ['edad_inicial' => '50', 'edad_final' => '20', 'desde' => '2026-02-01', 'hasta' => '2026-01-01'])->aviso();

        $this->assertStringContainsString('Las fechas venían al revés', $aviso);
        $this->assertStringContainsString('Las edades venían al revés: se buscó de 20 a 50 años', $aviso);
    }

    public function test_la_cabecera_impresa_lleva_el_rango_la_edad_y_los_filtros_puestos(): void
    {
        $sinFiltros = $this->vista([])->descripcionDeLosFiltros();
        $this->assertSame(['Ingreso desde', 'Ingreso hasta', 'Edad'], array_column($sinFiltros, 'etiqueta'));

        $conFiltros = $this->vista(
            [],
            ['sexo' => 'F', 'estado_civil' => 'S', 'procedencia' => '02'],
            [(object) ['codesegemp' => '02', 'nombre' => 'PDVSA']],
        )->descripcionDeLosFiltros();

        $this->assertSame(['Ingreso desde', 'Ingreso hasta', 'Edad', 'Sexo', 'Estado civil', 'Empresa'], array_column($conFiltros, 'etiqueta'));
        $this->assertSame('PDVSA', end($conFiltros)['valor']);
        $this->assertSame('Soltero(a)', $conFiltros[4]['valor']);
    }

    public function test_las_opciones_de_procedencia_son_todas_personal_y_el_catalogo_sin_repetir_el_00(): void
    {
        $vm = $this->vista([], ['procedencia' => '02'], [
            (object) ['codesegemp' => '00', 'nombre' => 'PERSONAL'],
            (object) ['codesegemp' => '02', 'nombre' => 'PDVSA'],
            (object) ['codesegemp' => '03', 'nombre' => 'SEGUROS'],
        ]);

        $opciones = $vm->opcionesDeProcedencia();

        $this->assertSame(['', 'personal', '02', '03'], array_column($opciones, 'valor'));
        $this->assertSame(['Todos', 'Personal', 'PDVSA', 'SEGUROS'], array_column($opciones, 'texto'));
        $this->assertSame([false, false, true, false], array_column($opciones, 'elegida'));
    }

    public function test_los_valores_del_formulario_vuelven_tal_como_estan(): void
    {
        $vm = $this->vista([], ['desde' => '2024-02-03', 'hasta' => '2025-04-05', 'edad_inicial' => '18', 'edad_final' => '65', 'sexo' => 'M']);

        $this->assertSame('2024-02-03', $vm->desde());
        $this->assertSame('2025-04-05', $vm->hasta());
        $this->assertSame([18, 65], [$vm->edadInicial(), $vm->edadFinal()]);
        $this->assertSame('pacientes-por-rango', $vm->claveDelListado());
        $this->assertSame([false, true, false], array_column($vm->opcionesDeSexo(), 'elegida'));
    }
}
