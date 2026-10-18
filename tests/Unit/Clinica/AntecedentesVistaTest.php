<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\TipoDeAntecedentes;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesCatalogoVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * **Los antecedentes del paciente en la consulta** (WEB-4.4), sin base de datos: los chips de tipo y el filtro
 * de las dos columnas (A-06), los dos estados vacíos, la marca de alerta, los "vacíos" del legado y el `old()`
 * de un POST que falló.
 *
 * Todo lo que los ViewModels necesitan llega por el constructor (mismo criterio que los otros ViewModels de la
 * consulta). ⚠️ Extiende `Tests\TestCase` y no `PHPUnit\Framework\TestCase` porque `tipoDelAlta()` y el `old()`
 * de las filas usan la petición de Laravel (igual que `ConValoresDeFormulario` en las pantallas de
 * configuración); no hay `DatabaseTransactions` porque no se toca ninguna tabla.
 */
class AntecedentesVistaTest extends TestCase
{
    /** Los siete tipos del escritorio, en el orden de `codetipo` (Per., Fam., H.P., Obst., Gine., A.S., Otros). */
    private const TIPOS = [
        ['codigo' => '01', 'nombre' => 'PERSONAL'],
        ['codigo' => '02', 'nombre' => 'FAMILIAR'],
        ['codigo' => '03', 'nombre' => 'HABITOS PSICOBIOLOGICOS'],
        ['codigo' => '04', 'nombre' => 'OBSTETRICOS'],
        ['codigo' => '05', 'nombre' => 'GINECOLOGICOS'],
        ['codigo' => '06', 'nombre' => 'ANTECEDENTES QUIRURGICOS'],
        ['codigo' => '07', 'nombre' => 'VARIOS'],
    ];

    /**
     * Una fila como la devuelve `AntecedentesDelPaciente::delPaciente()`.
     *
     * `tipo2` es el valor **crudo** de la columna (`A` alerta, `B` la apagó un escritorio, `null` sin
     * marcar) y `alerta` el booleano ya resuelto.
     */
    private function antecedente(
        int $id,
        string $codigo,
        string $nombre,
        ?string $detalle = null,
        ?string $tipoCodigo = '01',
        ?string $tipoNombre = 'PERSONAL',
        bool $alerta = false,
        ?string $tipo2 = null,
        bool $enCatalogo = true,
    ): object {
        return (object) [
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'detalle' => $detalle,
            'alerta' => $alerta,
            'tipo2' => $tipo2,
            'tipoCodigo' => $tipoCodigo,
            'tipoNombre' => $tipoNombre,
            'descripcionCruda' => $nombre,
            'enCatalogo' => $enCatalogo,
        ];
    }

    private function vista(array $antecedentes = [], array $old = [], string $tipo = '01', array $pendientes = []): SeccionDeAntecedentesVista
    {
        return new SeccionDeAntecedentesVista(
            new Collection($antecedentes),
            self::TIPOS,
            $tipo,
            '/clinica/consulta/42/antecedentes',
            '/clinica/consulta/42?seccion=antecedentes',
            $old,
            $pendientes,
        );
    }

    // --------------------------------------------------------------------------- Los chips (A-06)

    public function test_los_chips_son_los_siete_tipos_del_catalogo_en_su_orden_con_uno_elegido(): void
    {
        $vista = $this->vista([$this->antecedente(1, '0001', 'ASMA')], [], '02');

        $chips = $vista->chips();

        $this->assertSame(array_column(self::TIPOS, 'nombre'), array_column($chips, 'nombre'));
        $this->assertSame(['FAMILIAR'], array_column(array_filter($chips, fn ($c) => $c['activa']), 'nombre'));
        // No hay «todos los tipos»: siempre hay exactamente uno elegido.
        $this->assertCount(1, array_filter($chips, fn ($c) => $c['activa']));
        foreach ($chips as $chip) {
            $this->assertStringContainsString('seccion=antecedentes', $chip['url']);
            $this->assertStringContainsString('tipo=' . $chip['codigo'], $chip['url']);
        }
    }

    public function test_sin_antecedentes_fuera_del_catalogo_no_hay_chip_sin_clasificar(): void
    {
        $vista = $this->vista([$this->antecedente(1, '0001', 'ASMA', null, '01')]);

        $this->assertCount(7, $vista->chips());
    }

    public function test_los_antecedentes_sin_tipo_agregan_el_chip_sin_clasificar_al_final(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'ASMA', null, '01'),
            // La fila del legado sin tipo: ni la columna ni el catálogo lo resuelven.
            $this->antecedente(2, '9999', 'VIEJO', null, null),
        ]);

        $chips = $vista->chips();

        $this->assertCount(8, $chips);
        $this->assertSame('Sin clasificar', $chips[7]['nombre']);
        $this->assertSame(TipoDeAntecedentes::SIN_CLASIFICAR, $chips[7]['codigo']);
        // El chip se abre con su `?tipo=` propio.
        $this->assertStringContainsString('tipo=' . TipoDeAntecedentes::SIN_CLASIFICAR, $chips[7]['url']);
    }

    public function test_cambiar_de_chip_no_pierde_lo_tildado_del_buscador(): void
    {
        $vista = $this->vista([], [], '01', ['0013', '0285']);

        foreach ($vista->chips() as $chip) {
            $this->assertStringContainsString('agregar[]=0013', $chip['url']);
            $this->assertStringContainsString('agregar[]=0285', $chip['url']);
        }
    }

    // ------------------------------------------------------------------------------ El filtro

    public function test_la_columna_muestra_solo_los_antecedentes_del_tipo_elegido(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'ASMA', null, '01'),
            $this->antecedente(2, '0002', 'DIABETES', null, '02', 'FAMILIAR'),
            $this->antecedente(3, '0003', 'TABACO', null, '03', 'HABITOS PSICOBIOLOGICOS'),
        ], [], '02');

        $this->assertSame(['DIABETES'], array_column($vista->filas(), 'nombre'));
        // El contador sigue siendo el total del paciente, no lo filtrado.
        $this->assertSame(3, $vista->total());
        $this->assertSame('3 antecedentes', $vista->resumen());
    }

    public function test_el_chip_sin_clasificar_muestra_los_que_no_caen_en_ningun_tipo(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'ASMA', null, '01'),
            $this->antecedente(2, '9999', 'VIEJO', null, null),
        ], [], TipoDeAntecedentes::SIN_CLASIFICAR);

        $this->assertSame(['VIEJO'], array_column($vista->filas(), 'nombre'));
        $this->assertSame('Sin clasificar', $vista->nombreDelTipo());
    }

    public function test_el_nombre_del_tipo_elegido_sale_del_catalogo(): void
    {
        $this->assertSame('FAMILIAR', $this->vista([], [], '02')->nombreDelTipo());
        $this->assertNull($this->vista([], [], '99')->nombreDelTipo(), 'Un tipo que no es del catálogo no inventa nombre.');
        $this->assertNull($this->vista([], [], '')->nombreDelTipo());
    }

    // ------------------------------------------------------------------------------- El estado

    public function test_sin_antecedentes_la_seccion_esta_vacia(): void
    {
        $vista = $this->vista([], [], '01');

        $this->assertTrue($vista->estaVacio());
        $this->assertFalse($vista->sinAntecedentesDeEsteTipo(), 'Sin nada no es «sin antecedentes de este tipo».');
        $this->assertSame(0, $vista->total());
        $this->assertSame([], $vista->filas());
        $this->assertSame('Sin cargar', $vista->estado()['texto']);
        $this->assertSame('neutro', $vista->estado()['tono']);
    }

    /**
     * Los dos estados vacíos son distintos: el paciente **no tiene nada** o no tiene **de ese tipo**. El
     * segundo no puede decir el primero —sería falso— y por eso son dos banderas.
     */
    public function test_un_paciente_con_antecedentes_de_otro_tipo_no_esta_vacio(): void
    {
        $vista = $this->vista([$this->antecedente(1, '0001', 'ASMA', null, '01')], [], '02');

        $this->assertFalse($vista->estaVacio());
        $this->assertTrue($vista->sinAntecedentesDeEsteTipo());
        $this->assertSame([], $vista->filas());
    }

    public function test_con_antecedentes_el_estado_los_cuenta_y_avisa_si_hay_alerta(): void
    {
        $sinAlerta = $this->vista([$this->antecedente(1, '0001', 'ASMA')]);
        $this->assertSame('Cargados', $sinAlerta->estado()['texto']);
        $this->assertSame('exito', $sinAlerta->estado()['tono']);

        $conAlerta = $this->vista([
            $this->antecedente(1, '0001', 'ASMA', null, '01', 'PERSONAL', true, 'A'),
            $this->antecedente(2, '0002', 'DIABETES'),
        ]);
        $this->assertSame(1, $conAlerta->alertas());
        $this->assertSame('Con alerta', $conAlerta->estado()['texto']);
        $this->assertStringContainsString('1 antecedente marcado', $conAlerta->estado()['titulo']);
    }

    /**
     * Sólo el `'A'` de la fuente es alerta: la `B` que dejó la casilla apagada de un escritorio y el `null`
     * de las filas que inserta el botón *Pasar* se muestran **sin marca** (no se interpretan).
     */
    public function test_solo_la_a_de_la_fuente_es_alerta(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'ALERTA', null, '01', 'PERSONAL', true, 'A'),
            $this->antecedente(2, '0002', 'APAGADA', null, '01', 'PERSONAL', false, 'B'),
            $this->antecedente(3, '0003', 'SIN MARCAR', null, '01', 'PERSONAL', false, null),
        ]);

        $filas = $vista->filas();

        $this->assertTrue($filas[0]['alerta']);
        $this->assertFalse($filas[1]['alerta']);
        $this->assertFalse($filas[2]['alerta']);
        $this->assertSame(1, $vista->alertas());
    }

    // --------------------------------------------------------------------- Los vacíos del legado

    public function test_el_detalle_vacio_se_muestra_como_raya_y_el_largo_se_recorta(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '0001', 'SIN DETALLE', null),
            $this->antecedente(2, '0002', 'CON DETALLE', "  II CESAREAS\nLA ULTIMA HACE 5 AÑOS  "),
            $this->antecedente(3, '0003', 'LARGO', str_repeat('x', 200)),
        ]);

        $filas = $vista->filas();

        // Sin detalle: `null` (la vista pinta la raya del dato ausente).
        $this->assertSame('', $filas[0]['detalle']);
        $this->assertNull($filas[0]['detalleCorto']);
        // El detalle se colapsa para el listado, y el `<input>` lleva el texto tal cual (la vista lo pinta
        // sin recortar: es el dato que se edita).
        $this->assertSame('II CESAREAS LA ULTIMA HACE 5 AÑOS', $filas[1]['detalleCorto']);
        $this->assertSame("  II CESAREAS\nLA ULTIMA HACE 5 AÑOS  ", $filas[1]['detalle']);
        $this->assertSame(158, mb_strlen($filas[2]['detalleCorto']));
        $this->assertStringEndsWith('…', $filas[2]['detalleCorto']);
        $this->assertSame(200, mb_strlen($filas[2]['detalle']));
    }

    public function test_un_antecedente_que_ya_no_esta_en_el_catalogo_lo_avisa(): void
    {
        $vista = $this->vista([
            $this->antecedente(1, '9999', 'VIEJO', null, '01', 'PERSONAL', false, null, false),
        ]);

        $this->assertSame('ya no está en tu catálogo', $vista->filas()[0]['avisoCatalogo']);
    }

    // ------------------------------------------------------------------------------ El `old()`

    public function test_el_old_del_post_que_fallo_no_borra_lo_escrito(): void
    {
        $vista = $this->vista(
            [$this->antecedente(7, '0001', 'ASMA', 'lo guardado', '01', 'PERSONAL', false, null)],
            ['filas' => [7 => ['detalle' => 'lo que se escribió', 'alerta' => 'A']]],
        );

        $fila = $vista->filas()[0];

        $this->assertSame('lo que se escribió', $fila['detalle']);
        $this->assertTrue($fila['alerta']);
    }

    public function test_el_old_no_pisa_las_filas_que_no_vinieron(): void
    {
        $vista = $this->vista(
            [
                $this->antecedente(7, '0001', 'ASMA', 'lo guardado', '01', 'PERSONAL', true, 'A'),
                $this->antecedente(8, '0002', 'DIABETES', 'otra cosa', '01', 'PERSONAL', false, null),
            ],
            ['filas' => [7 => ['detalle' => 'nuevo', 'alerta' => 'B']]],
        );

        $filas = $vista->filas();

        $this->assertSame('nuevo', $filas[0]['detalle']);
        $this->assertFalse($filas[0]['alerta']);
        // La fila 8 no vino en el `old()`: se muestra como está guardada.
        $this->assertSame('otra cosa', $filas[1]['detalle']);
        $this->assertFalse($filas[1]['alerta']);
    }

    // --------------------------------------------------------------------------- El buscador

    private function catalogo(array $resultados = [], array $yaEsta = [], string $buscar = '', string $tipo = '', array $consulta = []): SeccionDeAntecedentesCatalogoVista
    {
        return new SeccionDeAntecedentesCatalogoVista(
            new Collection($resultados),
            $yaEsta,
            self::TIPOS,
            '/clinica/consulta/42',
            '/clinica/consulta/42/antecedentes/crear',
            $buscar,
            $tipo,
            $consulta,
            '/clinica/consulta/42/antecedentes',
        );
    }

    public function test_el_buscador_marca_lo_que_el_paciente_ya_tiene_y_lo_pendiente(): void
    {
        $catalogo = $this->catalogo(
            [
                (object) ['codigo' => '0001', 'nombre' => 'ASMA', 'tipoCodigo' => '01', 'tipoNombre' => 'PERSONAL'],
                (object) ['codigo' => '0002', 'nombre' => 'DIABETES', 'tipoCodigo' => '02', 'tipoNombre' => 'FAMILIAR'],
                (object) ['codigo' => '0003', 'nombre' => 'TABACO', 'tipoCodigo' => '03', 'tipoNombre' => 'HABITOS PSICOBIOLOGICOS'],
            ],
            ['0001'],
            '',
            '01',
            ['agregar' => '0003'],
        );

        $resultados = $catalogo->resultados();

        $this->assertTrue($resultados[0]['yaEsta'], 'ASMA ya está en el paciente');
        $this->assertFalse($resultados[0]['pendiente']);
        $this->assertFalse($resultados[1]['yaEsta']);
        $this->assertTrue($resultados[2]['pendiente'], 'TABACO quedó tildado en el buscador');
        $this->assertSame('FAMILIAR', $resultados[1]['tipo']);
        $this->assertSame(['0003'], $catalogo->pendientesDelBuscador());
        $this->assertStringContainsString('Tildaste un antecedente', $catalogo->avisoDePendientes());
        // El "Agregar" de una fila conserva lo que ya estaba tildado y suma el suyo.
        $urlAgregar = $catalogo->urlAgregar('0002');
        $this->assertStringContainsString('/clinica/consulta/42?seccion=antecedentes', $urlAgregar);
        $this->assertStringContainsString('agregar[]=0003', $urlAgregar);
        $this->assertStringContainsString('agregar[]=0002', $urlAgregar);
        // Y la URL del guardado lleva los dos: la sección y los pendientes.
        $this->assertStringContainsString('seccion=antecedentes', $catalogo->urlGuardar());
        $this->assertStringContainsString('agregar[]=0003', $catalogo->urlGuardar());
    }

    /** Agregar no puede perder el chip ni la búsqueda: la columna vuelve al tipo y al texto que se miraban. */
    public function test_agregar_conserva_el_tipo_y_la_busqueda(): void
    {
        $catalogo = $this->catalogo([], [], 'diab', '02');

        $this->assertStringContainsString('tipo=02', $catalogo->urlAgregar('0002'));
        $this->assertStringContainsString('buscar=diab', $catalogo->urlAgregar('0002'));
        // «Limpiar» se lleva la búsqueda pero **no** el tipo.
        $this->assertStringContainsString('tipo=02', $catalogo->urlLimpiar());
        $this->assertStringNotContainsString('buscar=', $catalogo->urlLimpiar());
    }

    public function test_el_buscador_sin_pendientes_no_avisa_nada(): void
    {
        $catalogo = $this->catalogo();

        $this->assertSame([], $catalogo->pendientesDelBuscador());
        $this->assertSame([], $catalogo->resultados());
        $this->assertSame('Tu catálogo no tiene antecedentes que coincidan', $catalogo->sinResultados()['titulo']);
    }

    /** Sin «todos los tipos», el catálogo vacío se explica por el tipo: no es lo mismo que el catálogo vacío. */
    public function test_el_catalogo_vacio_se_explica_por_el_tipo_elegido(): void
    {
        $vacio = $this->catalogo([], [], '', '04')->sinResultados();
        $this->assertStringContainsString('no tiene antecedentes de OBSTETRICOS', $vacio['titulo']);

        // Con una búsqueda escrita, el estado vacío es el de la búsqueda.
        $buscado = $this->catalogo([], [], 'xyz', '04')->sinResultados();
        $this->assertSame('Tu catálogo no tiene antecedentes que coincidan', $buscado['titulo']);
    }

    /** El alta del catálogo viene con el tipo del chip activo, para que lo creado caiga donde se está mirando. */
    public function test_el_alta_del_catalogo_viene_con_el_tipo_del_chip(): void
    {
        $this->assertSame('05', $this->catalogo([], [], '', '05')->tipoDelAlta());
        $this->assertSame('GINECOLOGICOS', $this->catalogo([], [], '', '05')->nombreDelTipo());
    }
}
