<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Bitacora\DiccionarioDeLaBitacora;
use App\Clinica\Bitacora\FiltroDeBitacora;
use App\Clinica\Bitacora\Vistas\BitacoraVista;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * La bitácora sin base de datos (WEB-8.5): el diccionario de etiquetas, el filtro que sale de la URL y el ViewModel que redacta
 * cada línea. `Tests\TestCase` porque la hora del consultorio sale de la configuración (trampa 68 de `TRAMPAS.md`).
 */
class BitacoraVistaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 15:00:00');   // 11:00 en Caracas
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function grupo(array $extra = []): array
    {
        return $extra + [
            'ultimo' => '2026-10-12 14:32:10', 'user_id' => 7, 'origen' => 'web', 'tabla' => 'cola', 'registro' => 5, 'filas' => 1,
            'operaciones' => ['updated'], 'columnas' => ['estado' => '1'], 'paciente' => null, 'consulta' => null,
        ];
    }

    private function vista(array $grupos, ?FiltroDeBitacora $filtro = null, array $pagina = []): BitacoraVista
    {
        return new BitacoraVista(
            $pagina + ['grupos' => $grupos, 'hay_mas' => false, 'hay_anterior' => false, 'sin_pacientes' => false, 'demasiados_pacientes' => false],
            [7 => ['nombre' => 'María Gómez', 'rol' => 'secretaría']],
            [['id' => 7, 'nombre' => 'María Gómez', 'rol' => 'secretaría']],
            $filtro ?? FiltroDeBitacora::desde([]),
            ['base' => 'https://x.test/clinica/bitacora', 'paciente' => fn (int $id) => "https://x.test/p/{$id}", 'consulta' => fn (int $id) => "https://x.test/c/{$id}"],
        );
    }

    // ------------------------------------------------------------------------------ El diccionario

    public function test_las_tablas_tienen_su_articulo_y_lo_desconocido_un_nombre_generico(): void
    {
        $this->assertSame('una cita', DiccionarioDeLaBitacora::conArticulo('cola'));
        $this->assertSame('un récipe', DiccionarioDeLaBitacora::conArticulo('recipe_detalle'));
        $this->assertSame('una solicitud de estudios', DiccionarioDeLaBitacora::conArticulo('radiologia_obs'));
        $this->assertSame('un otro dato del consultorio', DiccionarioDeLaBitacora::conArticulo('x_y_z'));
        $this->assertSame(DiccionarioDeLaBitacora::OTRO, DiccionarioDeLaBitacora::etiquetaDeTabla('x_y_z'));
    }

    public function test_las_columnas_se_etiquetan_o_se_humanizan(): void
    {
        $this->assertSame('Monto pagado', DiccionarioDeLaBitacora::etiquetaDeColumna('cola', 'monto_pagado'));
        $this->assertSame('Motivo', DiccionarioDeLaBitacora::etiquetaDeColumna('cola', 'tipo'));
        $this->assertSame('Tipo de antecedente', DiccionarioDeLaBitacora::etiquetaDeColumna('antece_paciente', 'tipo'));
        $this->assertSame('Una columna rara', DiccionarioDeLaBitacora::etiquetaDeColumna('cola', 'una_columna_rara'));
    }

    public function test_solo_unos_pocos_valores_son_visibles_y_los_demas_nunca(): void
    {
        $this->assertSame('25,00', DiccionarioDeLaBitacora::valorVisible('cola', 'monto', '25'));
        $this->assertSame('9:30 AM', DiccionarioDeLaBitacora::valorVisible('cola', 'hora_ini', '09:30:00'));
        $this->assertSame('12/10/2026', DiccionarioDeLaBitacora::valorVisible('cola', 'fecha', '2026-10-12 00:00:00'));
        $this->assertSame('atendida', DiccionarioDeLaBitacora::valorVisible('cola', 'atendido', '1'));
        $this->assertNull(DiccionarioDeLaBitacora::valorVisible('consultas', 'evolucion', 'Dolor'));
        $this->assertNull(DiccionarioDeLaBitacora::valorVisible('cola', 'sms_text', 'hola'));
        $this->assertNull(DiccionarioDeLaBitacora::valorVisible('cola', 'monto', null));
        $this->assertSame(['monto', 'monto_pagado', 'fecha', 'hora_ini', 'hora_fin', 'atendido'], DiccionarioDeLaBitacora::columnasVisiblesDe('cola'));
    }

    public function test_toda_tabla_con_paciente_esta_en_el_diccionario_de_tipos(): void
    {
        foreach (DiccionarioDeLaBitacora::tablasConPaciente() as $tabla) {
            $this->assertNotNull(DiccionarioDeLaBitacora::tipoDeTabla($tabla), "{$tabla} se puede relacionar con un paciente pero no tiene tipo.");
        }

        $this->assertContains('cola', DiccionarioDeLaBitacora::tablasDelTipo('cita'));
        $this->assertSame([], DiccionarioDeLaBitacora::tablasDelTipo('no-existe'));
    }

    // -------------------------------------------------------------------------------------- El filtro

    public function test_el_filtro_ignora_lo_que_no_es_valido(): void
    {
        $filtro = FiltroDeBitacora::desde([
            'desde' => '2026-02-31', 'hasta' => 'mañana', 'usuario' => 'abc', 'tipo' => 'xxx', 'paciente' => '  Ana  ', 'page' => '-4',
        ]);

        $this->assertNull($filtro->desde);
        $this->assertNull($filtro->hasta);
        $this->assertSame('', $filtro->usuario);
        $this->assertSame('', $filtro->tipo);
        $this->assertSame('Ana', $filtro->paciente);
        $this->assertSame(1, $filtro->pagina);
        $this->assertTrue($filtro->hayFiltro());
        $this->assertFalse(FiltroDeBitacora::desde([])->hayFiltro());
    }

    public function test_el_filtro_convierte_el_rango_a_utc_desde_la_hora_del_consultorio(): void
    {
        $filtro = FiltroDeBitacora::desde(['desde' => '2026-10-05', 'hasta' => '2026-10-05', 'usuario' => 'sin', 'tipo' => 'cita', 'page' => '3']);

        $this->assertSame('2026-10-05 04:00:00', $filtro->desdeUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 04:00:00', $filtro->hastaUtc()->format('Y-m-d H:i:s'));
        $this->assertSame(['desde' => '2026-10-05', 'hasta' => '2026-10-05', 'usuario' => 'sin', 'tipo' => 'cita'], $filtro->parametros());
        $this->assertSame(3, $filtro->pagina);
        $this->assertSame(1, $filtro->conPagina(0)->pagina);
    }

    // ----------------------------------------------------------------------------------- Las líneas

    public function test_una_linea_dice_cuando_quien_y_que(): void
    {
        $linea = $this->vista([$this->grupo(['columnas' => ['estado' => '1', 'monto' => '30']])])->lineas()[0];

        $this->assertSame('Hoy 10:32 AM', $linea['cuando']);
        $this->assertSame('12/10/2026 10:32:10 AM', $linea['cuandoCompleto']);
        $this->assertSame(['nombre' => 'María Gómez', 'rol' => 'secretaría', 'sinUsuario' => false], $linea['quien']);
        $this->assertSame('Editó una cita', $linea['accion']);
        $this->assertSame('Cambió: Estado, Monto: 30,00', $linea['detalle']);
        $this->assertNull($linea['paciente']);
    }

    public function test_el_verbo_lo_dicta_la_operacion_mas_fuerte(): void
    {
        $verbos = fn (array $operaciones, string $tabla = 'recipes') => $this->vista([$this->grupo(['operaciones' => $operaciones, 'tabla' => $tabla, 'columnas' => []])])->lineas()[0]['accion'];

        $this->assertSame('Creó un récipe', $verbos(['created']));
        $this->assertSame('Creó un récipe', $verbos(['created', 'updated']));
        $this->assertSame('Eliminó un récipe', $verbos(['updated', 'deleted']));
        $this->assertSame('Reordenó la cola de citas', $verbos(['reorder'], 'cola'));
        $this->assertSame('Editó un récipe', $verbos(['updated']));
    }

    public function test_muchas_columnas_se_resumen_y_el_contenido_clinico_no_se_muestra(): void
    {
        $columnas = [];
        foreach (range(1, 11) as $i) {
            $columnas['col_' . $i] = 'SECRETO ' . $i;
        }

        $detalle = $this->vista([$this->grupo(['tabla' => 'consultas', 'columnas' => $columnas])])->lineas()[0]['detalle'];

        $this->assertStringContainsString('y 3 más', $detalle);
        $this->assertStringNotContainsString('SECRETO', $detalle);
    }

    public function test_ayer_y_fechas_viejas(): void
    {
        $lineas = $this->vista([
            $this->grupo(['ultimo' => '2026-10-11 20:15:00']),
            $this->grupo(['ultimo' => '2026-09-30 13:00:00']),
        ])->lineas();

        $this->assertSame('Ayer 4:15 PM', $lineas[0]['cuando']);
        $this->assertSame('30/09/2026 9:00 AM', $lineas[1]['cuando']);
    }

    public function test_quien_cuando_no_hay_usuario(): void
    {
        $quien = fn (array $extra) => $this->vista([$this->grupo($extra + ['user_id' => null])])->lineas()[0]['quien'];

        $this->assertSame('App del móvil', $quien(['origen' => 'mobile'])['nombre']);
        $this->assertSame('Sistema anterior', $quien(['origen' => 'escritorio'])['nombre']);
        $this->assertSame('Sin usuario', $quien(['origen' => 'web'])['nombre']);
        $this->assertTrue($quien(['origen' => 'web'])['sinUsuario']);
        $this->assertSame('Usuario eliminado', $quien(['user_id' => 999])['nombre']);
    }

    public function test_los_enlaces_al_paciente_y_a_la_consulta(): void
    {
        $linea = $this->vista([$this->grupo(['tabla' => 'consultas', 'paciente' => ['id' => 4, 'nombre' => 'Alvarez, Ana'], 'consulta' => 12])])->lineas()[0];

        $this->assertSame('Alvarez, Ana', $linea['paciente']);
        $this->assertSame('https://x.test/p/4', $linea['urlPaciente']);
        $this->assertSame('https://x.test/c/12', $linea['urlConsulta']);
    }

    // ------------------------------------------------------------------------------ Páginas y estados

    public function test_las_paginas_conservan_los_filtros(): void
    {
        $filtro = FiltroDeBitacora::desde(['tipo' => 'cita', 'page' => '2']);
        $vista = $this->vista([$this->grupo()], $filtro, ['hay_mas' => true, 'hay_anterior' => true]);

        $this->assertSame('https://x.test/clinica/bitacora?tipo=cita&page=3', $vista->urlMasAntiguas());
        $this->assertSame('https://x.test/clinica/bitacora?tipo=cita', $vista->urlMasRecientes());
        $this->assertSame('Mostrando 51–51 (hay más)', $vista->rango());
    }

    public function test_los_estados_vacios_distinguen_el_motivo(): void
    {
        $this->assertSame('Todavía no hay cambios registrados', $this->vista([])->vacio()['titulo']);
        $this->assertSame('Ningún cambio coincide con tu búsqueda', $this->vista([], FiltroDeBitacora::desde(['tipo' => 'cita']))->vacio()['titulo']);
        $this->assertStringContainsString('No encontramos pacientes con «Ana»', $this->vista([], FiltroDeBitacora::desde(['paciente' => 'Ana']), ['sin_pacientes' => true])->vacio()['titulo']);
    }

    public function test_las_opciones_del_filtro(): void
    {
        $vista = $this->vista([]);
        $opciones = $vista->opcionesDeUsuario();

        $this->assertSame('', $opciones[0]['valor']);
        $this->assertSame('sin', $opciones[1]['valor']);
        $this->assertSame(['valor' => '7', 'texto' => 'María Gómez (secretaría)'], $opciones[2]);
        $this->assertArrayHasKey('Récipes', $vista->opcionesDeTipo());
        $this->assertSame('Cita', $vista->opcionesDeTipo()['Agenda']['cita']);
    }
}
