<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Embarazo\EdadGestacional;
use App\Clinica\Listados\FiltroDeEmbarazos;
use App\Clinica\Listados\ListadosDisponibles;
use App\Clinica\Listados\Vistas\ListadoDeEmbarazosVista;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * **El listado de embarazos / partos entre fechas** (WEB-6.8), sin base de datos: la cuenta del rango —que un
 * rango de fechas se traduce en "FPP dentro del rango", los **−283** del legado, con sus bordes—, la del
 * filtro de edad y la del modo por semanas, más las filas ya formateadas del ViewModel.
 *
 * Lo que se fija acá es lo que el escritorio hace y no se puede reimplementar "a ojo":
 * `w_reportes_consultas.srw:1312-1313` corre el rango **−283 días** antes de compararlo con la FUR, el filtro
 * de edad sale de la misma cuenta de años que muestra la columna "Edad", y la semana de gestación es una
 * división entera de los días vividos desde la FUR.
 */
class ListadoDeEmbarazosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // El día del consultorio, fijo: la fecha final por defecto y la ventana de la semana salen de "hoy".
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

    // --------------------------------------------------------------------- Los filtros y sus cuentas

    public function test_un_rango_de_fechas_se_traduce_en_la_fur_de_la_fpp(): void
    {
        $filtro = FiltroDeEmbarazos::de(['desde' => '2026-06-01', 'hasta' => '2026-06-30'], $this->hoy());

        // El rango elegido son las fechas de la **FPP**; la columna que se filtra es la FUR, corrida −283.
        $this->assertSame('2026-06-01', $filtro->rango()->desde());
        $this->assertSame('2026-06-30', $filtro->rango()->hasta());

        $fur = $filtro->rangoDeFur();
        $this->assertSame('2025-08-22', $fur->desde());
        $this->assertSame('2025-09-20', $fur->hasta());

        // Los bordes, con la misma cuenta que la columna F.P.P. de la pantalla: la FUR del extremo da
        // exactamente la FPP del extremo, así que el primero y el último día del rango entran.
        $this->assertSame('2026-06-01', EdadGestacional::fpp($fur->desde(), FiltroDeEmbarazos::DIAS_DE_LA_FPP));
        $this->assertSame('2026-06-30', EdadGestacional::fpp($fur->hasta(), FiltroDeEmbarazos::DIAS_DE_LA_FPP));

        // ...y los 283 son los de los reportes, no otros: un día antes de la FUR del extremo ya queda afuera.
        $unDiaAntes = Carbon::createFromFormat('!Y-m-d', $fur->desde())->subDay()->toDateString();
        $this->assertSame('2026-05-31', EdadGestacional::fpp($unDiaAntes, FiltroDeEmbarazos::DIAS_DE_LA_FPP));
    }

    public function test_los_valores_por_defecto_son_los_del_escritorio(): void
    {
        // `em_3.text = '01/01/2000'` y `em_4.text = today()`; las edades, 1 y 110.
        $filtro = FiltroDeEmbarazos::de([], $this->hoy());

        $this->assertSame('2000-01-01', $filtro->rango()->desde());
        $this->assertSame('2026-10-14', $filtro->rango()->hasta());
        $this->assertSame(1, $filtro->edadInicial());
        $this->assertSame(110, $filtro->edadFinal());
        $this->assertFalse($filtro->porSemanas());
        $this->assertTrue($filtro->esValido());
        $this->assertFalse($filtro->hayFiltro(), 'sin tocar nada no hay nada que limpiar');
    }

    public function test_el_rango_al_reves_se_da_vuelta_y_se_avisa(): void
    {
        // El escritorio corta con un `Messagebox`; la web busca igual y lo dice.
        $filtro = FiltroDeEmbarazos::de(['desde' => '2026-06-30', 'hasta' => '2026-06-01'], $this->hoy());

        $this->assertSame('2026-06-01', $filtro->rango()->desde());
        $this->assertSame('2026-06-30', $filtro->rango()->hasta());
        $this->assertTrue($filtro->seVoltearonLasFechas());
        $this->assertTrue($filtro->hayFiltro());
        $this->assertTrue($filtro->esValido());
    }

    public function test_la_edad_se_traduce_en_fechas_de_nacimiento(): void
    {
        // La edad `30` cumplida hoy es haber nacido entre el 15/10/1995 y el 14/10/1996.
        $nacimiento = FiltroDeEmbarazos::de(['edad_inicial' => '30', 'edad_final' => '30'], $this->hoy())->rangoDeNacimiento();
        $this->assertSame('1995-10-15', $nacimiento->desde());
        $this->assertSame('1996-10-14', $nacimiento->hasta());

        // Los bordes: el que nace en el extremo alto cumple 30 hoy; el del día anterior ya cumplió 31.
        $this->assertSame(30, Carbon::createFromFormat('!Y-m-d', $nacimiento->hasta())->diffInYears($this->hoy()));
        $this->assertSame(31, Carbon::createFromFormat('!Y-m-d', $nacimiento->desde())->subDay()->diffInYears($this->hoy()));

        // Por defecto, 1..110.
        $porDefecto = FiltroDeEmbarazos::de([], $this->hoy())->rangoDeNacimiento();
        $this->assertSame('1915-10-15', $porDefecto->desde());
        $this->assertSame('2025-10-14', $porDefecto->hasta());
    }

    public function test_las_edades_al_reves_se_dan_vuelta(): void
    {
        $filtro = FiltroDeEmbarazos::de(['edad_inicial' => '40', 'edad_final' => '20'], $this->hoy());

        $this->assertSame(20, $filtro->edadInicial());
        $this->assertSame(40, $filtro->edadFinal());
        $this->assertTrue($filtro->seVoltearonLasEdades());
        $this->assertFalse($filtro->seVoltearonLasFechas());
        $this->assertSame('1985-10-15', $filtro->rangoDeNacimiento()->desde());
    }

    public function test_la_semana_de_gestacion_se_traduce_en_su_ventana_de_fur(): void
    {
        // La semana N son los días vividos desde la FUR entre 7N y 7N+6: la misma cuenta de `EdadGestacional`.
        $casos = [
            [1, '2026-10-01', '2026-10-07'],
            [25, '2026-04-16', '2026-04-22'],
            [40, '2026-01-01', '2026-01-07'],
        ];

        foreach ($casos as [$semana, $desde, $hasta]) {
            $filtro = FiltroDeEmbarazos::de(['por_semanas' => '1', 'semana' => (string) $semana], $this->hoy());

            $this->assertTrue($filtro->porSemanas());
            $this->assertSame($semana, $filtro->semana());
            $this->assertSame($desde, $filtro->rangoDeFur()->desde(), 'semana ' . $semana);
            $this->assertSame($hasta, $filtro->rangoDeFur()->hasta(), 'semana ' . $semana);

            // Los dos bordes de la ventana son de esa semana, según la regla de la edad gestacional.
            $this->assertSame($semana, EdadGestacional::de($desde, $this->hoy())->semanas());
            $this->assertSame($semana, EdadGestacional::de($hasta, $this->hoy())->semanas());
        }

        // Fuera de la ventana la semana cambia: un día antes de la FUR del extremo son 26 semanas.
        $anterior = Carbon::createFromFormat('!Y-m-d', '2026-04-16')->subDay()->toDateString();
        $this->assertSame(26, EdadGestacional::de($anterior, $this->hoy())->semanas());
    }

    public function test_la_semana_que_no_se_entiende_deja_el_filtro_sin_datos(): void
    {
        foreach (['0', '41', '', 'abc', '25a'] as $pedida) {
            $filtro = FiltroDeEmbarazos::de(['por_semanas' => '1', 'semana' => $pedida], $this->hoy());

            $this->assertTrue($filtro->porSemanas(), 'la semana pedida: «' . $pedida . '»');
            $this->assertTrue($filtro->semanaInvalida(), 'la semana pedida: «' . $pedida . '»');
            $this->assertFalse($filtro->esValido());
            $this->assertNull($filtro->semana());
            $this->assertSame($pedida, $filtro->semanaPedida(), 'lo tecleado se conserva');
        }

        // Sin el modo por semanas, una "semana" que no se entiende se ignora y el rango sigue valiendo.
        $sinModo = FiltroDeEmbarazos::de(['semana' => 'abc'], $this->hoy());
        $this->assertFalse($sinModo->porSemanas());
        $this->assertFalse($sinModo->semanaInvalida());
        $this->assertTrue($sinModo->esValido());
    }

    public function test_una_fecha_que_no_se_entiende_vuelve_al_valor_por_defecto(): void
    {
        $filtro = FiltroDeEmbarazos::de(['desde' => 'no es una fecha', 'hasta' => ''], $this->hoy());

        $this->assertSame('2000-01-01', $filtro->rango()->desde());
        $this->assertSame('2026-10-14', $filtro->rango()->hasta());
        $this->assertFalse($filtro->hayFiltro());
    }

    // ------------------------------------------------------------------------------- El catálogo

    public function test_el_catalogo_de_listados_normaliza_la_clave(): void
    {
        $this->assertSame(['embarazos', 'pacientes-por-rango', 'consultas-por-rango'], ListadosDisponibles::claves());
        $this->assertSame('embarazos', ListadosDisponibles::normalizar('embarazos'));
        $this->assertSame('Embarazos y partos', ListadosDisponibles::titulo('embarazos'));
        $this->assertSame('pacientes-por-rango', ListadosDisponibles::normalizar('pacientes-por-rango'));
        $this->assertSame('Pacientes por rango', ListadosDisponibles::titulo('pacientes-por-rango'));

        // Una clave que todavía no tiene pantalla vuelve al listado por defecto, no a una pantalla a medias.
        $this->assertSame('embarazos', ListadosDisponibles::normalizar('no-existe'));
        $this->assertSame('embarazos', ListadosDisponibles::normalizar(null));
        $this->assertFalse(ListadosDisponibles::existe('otro'));
    }

    // ------------------------------------------------------------------------------ El ViewModel

    public function test_las_filas_salen_con_los_textos_formateados(): void
    {
        $filtro = FiltroDeEmbarazos::de(['desde' => '2026-06-01', 'hasta' => '2026-06-30'], $this->hoy());

        $vm = new ListadoDeEmbarazosVista(
            collect([$this->gesta()]),
            $filtro,
            collect([(object) ['codesegemp' => '02', 'nombre' => 'PDVSA']]),
        );

        $fila = $vm->filas()[0];

        $this->assertSame('Pérez, Ana María', $fila['nombre']);
        $this->assertSame('Historia 1001', $fila['historia']);
        $this->assertSame('12/03/2020', $fila['fingreso']);
        $this->assertSame('34 años', $fila['edad']);
        $this->assertSame('15/01/2026', $fila['fur']);
        // F.P.P. = FUR + 283, la misma cuenta que el `relativedate(fur, 283)` del DataWindow.
        $this->assertSame('25/10/2026', $fila['fpp']);
        $this->assertSame('PDVSA', $fila['procedencia']);
        $this->assertNull($fila['url'], 'sin la URL del controlador no hay enlace');
    }

    public function test_la_procedencia_muestra_el_codigo_si_no_esta_en_el_catalogo(): void
    {
        $vm = new ListadoDeEmbarazosVista(
            collect([
                $this->gesta(['codesegemp' => '99']),   // no está en el catálogo
                $this->gesta(['codesegemp' => '00']),   // PERSONAL: la regla de la ficha, no se muestra
                $this->gesta(['codesegemp' => null]),
            ]),
            FiltroDeEmbarazos::de([], $this->hoy()),
            collect([(object) ['codesegemp' => '02', 'nombre' => 'PDVSA']]),
        );

        $filas = $vm->filas();

        $this->assertSame('99', $filas[0]['procedencia']);
        $this->assertNull($filas[1]['procedencia']);
        $this->assertNull($filas[2]['procedencia']);
    }

    public function test_los_datos_ausentes_no_se_inventan(): void
    {
        $vm = new ListadoDeEmbarazosVista(
            collect([$this->gesta([
                'fnacimiento' => null,
                'numhistoria' => null,
                'fingreso'    => '0000-00-00',
            ])]),
            FiltroDeEmbarazos::de([], $this->hoy()),
        );

        $fila = $vm->filas()[0];

        $this->assertNull($fila['edad']);
        $this->assertSame('Sin historia', $fila['historia']);
        $this->assertSame('—', $fila['fingreso']);
    }

    public function test_el_titulo_cambia_con_el_modo_por_semanas(): void
    {
        $gestas = collect([$this->gesta()]);

        $porRango = new ListadoDeEmbarazosVista($gestas, FiltroDeEmbarazos::de([], $this->hoy()));
        $this->assertSame('Embarazos y partos', $porRango->titulo());

        $porSemana = new ListadoDeEmbarazosVista(
            $gestas,
            FiltroDeEmbarazos::de(['por_semanas' => '1', 'semana' => '25'], $this->hoy()),
        );
        // El título del DataWindow del legado: «Embarazos de N semanas de gestación».
        $this->assertSame('Embarazos de 25 semanas de gestación', $porSemana->titulo());

        $unaSemana = new ListadoDeEmbarazosVista(
            $gestas,
            FiltroDeEmbarazos::de(['por_semanas' => '1', 'semana' => '1'], $this->hoy()),
        );
        $this->assertSame('Embarazos de 1 semana de gestación', $unaSemana->titulo());
    }

    public function test_el_subtitulo_cuenta_las_gestas_y_dice_los_filtros(): void
    {
        $una = new ListadoDeEmbarazosVista(
            collect([$this->gesta()]),
            FiltroDeEmbarazos::de(['desde' => '2026-06-01', 'hasta' => '2026-06-30'], $this->hoy()),
        );

        $this->assertSame(
            '1 gesta · FPP entre el 01/06/2026 y el 30/06/2026 · pacientes de 1 a 110 años.',
            $una->subtitulo()
        );

        $varias = new ListadoDeEmbarazosVista(
            collect([$this->gesta(), $this->gesta(['paciente_id' => 52])]),
            FiltroDeEmbarazos::de(['por_semanas' => '1', 'semana' => '25', 'edad_inicial' => '20', 'edad_final' => '35'], $this->hoy()),
        );

        $this->assertSame('2 gestas · semana 25 de gestación · pacientes de 20 a 35 años.', $varias->subtitulo());
    }

    public function test_el_estado_vacio_distingue_la_semana_que_falta_de_la_busqueda_sin_resultados(): void
    {
        $sinSemana = new ListadoDeEmbarazosVista(
            collect(),
            FiltroDeEmbarazos::de(['por_semanas' => '1', 'semana' => '99'], $this->hoy()),
        );
        $this->assertTrue($sinSemana->estaVacio());
        $this->assertTrue($sinSemana->semanaInvalida());
        $this->assertSame('bi-exclamation-triangle', $sinSemana->vacio()['icono']);
        $this->assertSame('Falta la semana de gestación', $sinSemana->vacio()['titulo']);
        $this->assertSame('Falta la semana de gestación.', $sinSemana->subtitulo());

        $conFiltros = new ListadoDeEmbarazosVista(
            collect(),
            FiltroDeEmbarazos::de(['desde' => '2026-06-01', 'hasta' => '2026-06-30'], $this->hoy()),
        );
        $this->assertSame('bi-search', $conFiltros->vacio()['icono']);
        $this->assertFalse($conFiltros->semanaInvalida());

        $sinNada = new ListadoDeEmbarazosVista(collect(), FiltroDeEmbarazos::de([], $this->hoy()));
        $this->assertSame('bi-clipboard2-pulse', $sinNada->vacio()['icono']);
        $this->assertFalse($sinNada->hayFiltro());
    }

    public function test_el_aviso_dice_lo_que_se_corrigio_del_filtro(): void
    {
        $volteado = new ListadoDeEmbarazosVista(
            collect(),
            FiltroDeEmbarazos::de(['desde' => '2026-06-30', 'hasta' => '2026-06-01'], $this->hoy()),
        );
        $this->assertStringContainsString('al revés', $volteado->aviso());
        $this->assertStringContainsString('01/06/2026', $volteado->aviso());

        $sinAviso = new ListadoDeEmbarazosVista(collect(), FiltroDeEmbarazos::de([], $this->hoy()));
        $this->assertNull($sinAviso->aviso());
    }

    public function test_la_cabecera_impresa_lleva_el_rango_las_edades_y_la_semana(): void
    {
        $porRango = new ListadoDeEmbarazosVista(
            collect(),
            FiltroDeEmbarazos::de(['desde' => '2026-06-01', 'hasta' => '2026-06-30', 'edad_inicial' => '20', 'edad_final' => '35'], $this->hoy()),
        );
        $this->assertSame([
            ['etiqueta' => 'Desde', 'valor' => '01/06/2026'],
            ['etiqueta' => 'Hasta', 'valor' => '30/06/2026'],
            ['etiqueta' => 'Edad', 'valor' => '20 a 35 años'],
        ], $porRango->descripcionDeLosFiltros());

        $porSemana = new ListadoDeEmbarazosVista(
            collect(),
            FiltroDeEmbarazos::de(['por_semanas' => '1', 'semana' => '25'], $this->hoy()),
        );
        $this->assertSame([
            ['etiqueta' => 'Semana de gestación', 'valor' => '25'],
            ['etiqueta' => 'Edad', 'valor' => '1 a 110 años'],
        ], $porSemana->descripcionDeLosFiltros());
    }

    public function test_los_valores_del_formulario_vuelven_tal_como_estan(): void
    {
        $vm = new ListadoDeEmbarazosVista(
            collect(),
            FiltroDeEmbarazos::de(['desde' => '2026-06-01', 'hasta' => '2026-06-30', 'semana' => '99', 'por_semanas' => '1'], $this->hoy()),
            collect(),
            '/clinica/listados',
            '/clinica/listados/imprimir?desde=2026-06-01',
        );

        $this->assertSame('2026-06-01', $vm->desde());
        $this->assertSame('2026-06-30', $vm->hasta());
        $this->assertSame(1, $vm->edadInicial());
        $this->assertSame(110, $vm->edadFinal());
        $this->assertTrue($vm->porSemanas());
        $this->assertSame('99', $vm->semanaPedida(), 'lo que no se entiende se conserva para corregirlo');
        $this->assertSame('/clinica/listados', $vm->urlBase());
        $this->assertSame('/clinica/listados/imprimir?desde=2026-06-01', $vm->urlImprimir());
        $this->assertSame(ListadosDisponibles::EMBARAZOS, $vm->claveDelListado());
    }

    public function test_la_fila_enlaza_al_paciente_con_la_url_del_controlador(): void
    {
        $vm = new ListadoDeEmbarazosVista(
            collect([$this->gesta()]),
            FiltroDeEmbarazos::de([], $this->hoy()),
            collect(),
            '/clinica/listados',
            '/clinica/listados/imprimir',
            fn (int $paciente) => '/clinica/pacientes/' . $paciente,
        );

        $this->assertSame('/clinica/pacientes/51', $vm->filas()[0]['url']);
    }

    /** Una gesta como la devuelve `EmbarazosEntreFechas::filas()`. */
    private function gesta(array $datos = []): object
    {
        return (object) array_merge([
            'paciente_id'  => 51,
            'nombres'      => 'Ana María',
            'apellidos'    => 'Pérez',
            'numhistoria'  => '1001',
            'fingreso'     => '2020-03-12',
            'fnacimiento'  => '1992-10-14',
            'codesegemp'   => '02',
            'fur'          => '2026-01-15',
        ], $datos);
    }
}
