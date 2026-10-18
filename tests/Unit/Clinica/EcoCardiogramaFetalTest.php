<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\Vistas\EcoCardiogramaFetalVista;
use App\Clinica\Consulta\Vistas\OpcionesDelDesplegable;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa del dato y el ViewModel del ecocardiograma fetal** (WEB-6.3), sin base de datos.
 *
 * Es el contrato del mapa con el resto del sistema: la pantalla, la Action, la hoja y la historia leen de
 * `EcoCardiogramaFetal`, así que una columna que se olvide de entrar (o una que entre dos veces) se ve acá y no
 * en el navegador. Lo que estos casos fijan:
 *
 *  - **Los seis bloques** del formulario, en el orden del DataWindow vivo (`fuentes/ventana_consulta/`
 *    `d_ecocardiograma_fetal.srd`: no está en `datawin_procesos/`, trampas 102, 123 y 124), con los rótulos del
 *    escritorio.
 *  - **La tabla y su clave**: `ecocadiograma_fetal` (con el error de tipeo del legado, sin la «r») llaveada por
 *    `historia` + `consulta`.
 *  - **`eg` y `referido` quedan afuera**: existen, van ocultos en el DataWindow y en ginecología no se editan.
 *  - **Los códigos de los desplegables** del legado —la placenta desempata «Previa» con `M` porque el
 *    DataWindow tiene `P` duplicado— y, sobre todo, **la opción vacía adelante** (trampa 112).
 *  - **La ficha** (la fecha, la FUR, la gesta y la paridad) no cuenta como hallazgo, y la **precarga** de los
 *    dos memos es la de la ventana del escritorio.
 */
class EcoCardiogramaFetalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // "Hoy" fijo: la EG y la FPP salen de la FUR y de la fecha del día.
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------- El mapa del dato

    public function test_los_bloques_son_los_seis_del_formulario(): void
    {
        $bloques = EcoCardiogramaFetal::grupos();

        $this->assertSame(
            ['Ficha', 'El estudio', 'Datos biométricos', 'Datos anatómicos', 'Datos placento - funiculares',
                'Conclusión'],
            array_column($bloques, 'titulo'),
        );
    }

    public function test_las_columnas_editables_son_veintiocho_y_no_se_repiten(): void
    {
        $columnas = EcoCardiogramaFetal::columnas();

        // 7 de la ficha + 1 memo + 10 biométricos + 1 memo anatómico + 7 placento-funiculares + 2 de la
        // conclusión.
        $this->assertCount(28, $columnas);
        $this->assertSame($columnas, array_values(array_unique($columnas)));

        // ⚠️ `eg` y `referido` existen en la tabla y en el DataWindow, pero van OCULTOS (`visible="0"`): en
        // ginecología no se editan ni se imprimen, así que no están en el mapa y la Action no los toca.
        $this->assertNotContains('eg', $columnas);
        $this->assertNotContains('referido', $columnas);

        // La tabla trae el error de tipeo del legado (sin la «r») y la clave es `historia` + `consulta`.
        $this->assertSame('ecocadiograma_fetal', EcoCardiogramaFetal::TABLA);
        $this->assertSame('historia', EcoCardiogramaFetal::COLUMNA_HISTORIA);
        $this->assertSame('consulta', EcoCardiogramaFetal::COLUMNA_CONSULTA);
    }

    public function test_la_ficha_es_la_del_encabezado_y_no_cuenta_como_hallazgo(): void
    {
        // La fecha, la FUR, la gesta y la paridad entera (el `para`, los abortos, las cesáreas y los `otros`).
        $this->assertSame(
            ['fecha', 'fur', 'gesta', 'parto', 'aborto', 'cesarea', 'otros'],
            EcoCardiogramaFetal::COLUMNAS_QUE_NO_CUENTAN,
        );

        // Y son exactamente las del primer bloque del mapa.
        $this->assertSame(
            EcoCardiogramaFetal::COLUMNAS_QUE_NO_CUENTAN,
            array_column(EcoCardiogramaFetal::camposDelBloque('Ficha'), 'columna'),
        );
    }

    public function test_los_biometricos_son_numeros_en_milimetros_y_la_medida_lleva_percentil(): void
    {
        $campos = EcoCardiogramaFetal::camposDelBloque('Datos biométricos');

        $this->assertSame(
            ['biome_dbp', 'biome_dof', 'biome_cc', 'biome_humero', 'biome_femur', 'biome_saco', 'biome_ca',
                'biome_cardiaca', 'biome_toraxica', 'biome_relacion'],
            array_column($campos, 'columna'),
        );

        foreach ($campos as $campo) {
            $this->assertSame('numero', $campo['tipo'], $campo['columna'] . ' es una medida del estudio.');
        }

        // Las nueve medidas van en milímetros; la «MEDIDA» (el percentil) no: la hoja le agrega «(Percentil)».
        $this->assertSame('mm', $campos[0]['unidad']);
        $this->assertSame('mm', $campos[8]['unidad']);
        $this->assertNull($campos[9]['unidad']);
        $this->assertSame('(Percentil)', $campos[9]['sufijo']);
    }

    public function test_los_codigos_de_los_desplegables_son_los_del_datawindow(): void
    {
        // La placenta: el escritorio tiene `P` DUPLICADO (Posterior y Previa) y la web desempata Previa con
        // `M`, la misma desviación consciente del eco del 3.er trimestre (2026-10-13).
        $this->assertSame(
            [
                ['valor' => 'A', 'texto' => 'Anterior'],
                ['valor' => 'P', 'texto' => 'Posterior'],
                ['valor' => 'F', 'texto' => 'Fúndica'],
                ['valor' => 'D', 'texto' => 'Lateral Derecho'],
                ['valor' => 'I', 'texto' => 'Lateral Izquierdo'],
                ['valor' => 'M', 'texto' => 'Previa'],
            ],
            EcoCardiogramaFetal::opcionesDe('pla_funi_placenta'),
        );

        // El grado: `O` (la letra) guarda el grado 0, como el `values` del DataWindow.
        $this->assertSame(
            [['valor' => '0', 'texto' => 'O'], ['valor' => 'I', 'texto' => 'I'],
                ['valor' => 'II', 'texto' => 'II'], ['valor' => 'III', 'texto' => 'III']],
            EcoCardiogramaFetal::opcionesDe('pla_funi_grado'),
        );

        $this->assertSame(
            [['valor' => 'N', 'texto' => '3 ELEMENTOS VASCULARES'], ['valor' => 'A', 'texto' => 'ANORMAL']],
            EcoCardiogramaFetal::opcionesDe('pla_funi_cordon_umb'),
        );

        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Normal'], ['valor' => 'O', 'texto' => 'Oligohidramnios'],
                ['valor' => 'P', 'texto' => 'Polihidramnios']],
            EcoCardiogramaFetal::opcionesDe('pla_funi_liquido_anm'),
        );

        $this->assertSame(
            [['valor' => 'M', 'texto' => 'Masculino'], ['valor' => 'F', 'texto' => 'Femenino']],
            EcoCardiogramaFetal::opcionesDe('pla_funi_sexo'),
        );

        // La gesta: los números romanos I a XII con el código 1..12 (el `values` del DataWindow llega a XII).
        $gesta = EcoCardiogramaFetal::opcionesDe('gesta');
        $this->assertCount(12, $gesta);
        $this->assertSame(['valor' => '1', 'texto' => 'I'], $gesta[0]);
        $this->assertSame(['valor' => '12', 'texto' => 'XII'], $gesta[11]);

        // El texto que se muestra y el valor crudo del escritorio, cuando el código no está en la lista.
        $this->assertSame('Anterior', EcoCardiogramaFetal::textoDe('pla_funi_placenta', 'A'));
        $this->assertSame('Femenino', EcoCardiogramaFetal::textoDe('pla_funi_sexo', 'F'));
        $this->assertSame('Z', EcoCardiogramaFetal::textoDe('pla_funi_placenta', 'Z'));
        $this->assertNull(EcoCardiogramaFetal::textoDe('conclusion', 'none'));

        // Una columna que no está en el mapa no inventa rótulo ni opciones.
        $this->assertSame([], EcoCardiogramaFetal::opcionesDe('inventada'));
        $this->assertSame('inventada', EcoCardiogramaFetal::etiquetaDe('inventada'));
    }

    public function test_la_opcion_vacia_va_adelante_y_el_codigo_desconocido_al_final(): void
    {
        $codigos = fn (string $columna, string $valor) => array_column(
            OpcionesDelDesplegable::de(['opciones' => EcoCardiogramaFetal::opcionesDe($columna)], $valor),
            'valor',
        );

        // La vacía primero: sin ella el navegador manda la primera opción y el estudio nace con un hallazgo
        // que nadie escribió (trampa 112).
        $this->assertSame(['', 'A', 'P', 'F', 'D', 'I', 'M'], $codigos('pla_funi_placenta', ''));
        $this->assertSame(['', '0', 'I', 'II', 'III'], $codigos('pla_funi_grado', ''));
        $this->assertSame(['', 'M', 'F'], $codigos('pla_funi_sexo', ''));
        $this->assertSame(
            array_merge([''], array_map('strval', range(1, 12))),
            $codigos('gesta', ''),
        );

        // El código guardado que el escritorio no declara se conserva al final (no se traduce ni se pierde).
        $this->assertSame(['', 'A', 'P', 'F', 'D', 'I', 'M', 'Z'], $codigos('pla_funi_placenta', 'Z'));
    }

    // ------------------------------------------------------------------------------ La precarga del escritorio

    public function test_la_precarga_son_los_dos_textos_de_la_ventana_del_escritorio(): void
    {
        // Los dos textos de `w_cargar_visualizar_ecocardiograma_fetal.srw:180-181`, copiados literalmente (con
        // sus dos espacios dobles, que son los del fuente).
        $this->assertSame(
            'SE REALIZA EXPLORACIÓN CON TRANSDUCTOR DE 3,5 MHZ  EVIDENCIÁNDOSE: FETO ÚNICO EN SITUACIÓN '
                . 'LONGITUDINAL, PRESENTACIÓN CEFÁLICO. MOVIMIENTOS Y ACTIVIDAD CARDÍACA PRESENTES.',
            EcoCardiogramaFetal::TEXTO_INICIAL,
        );
        $this->assertStringStartsWith('CORAZON EN SITUS SOLITUS, TETRACAMERAL.', EcoCardiogramaFetal::TEXTO_ANATOMICO);
        $this->assertStringEndsWith('RELACION CARDIOTORACICA 1/3.', EcoCardiogramaFetal::TEXTO_ANATOMICO);
        $this->assertStringContainsString('EJE CARDIACO 45  A LA IZQUIERDA.', EcoCardiogramaFetal::TEXTO_ANATOMICO);

        $this->assertSame(
            [
                EcoCardiogramaFetal::COLUMNA_TEXTO_INICIO => EcoCardiogramaFetal::TEXTO_INICIAL,
                EcoCardiogramaFetal::COLUMNA_ANATOMIA => EcoCardiogramaFetal::TEXTO_ANATOMICO,
            ],
            EcoCardiogramaFetal::precarga(),
        );
    }

    // -------------------------------------------------------------------------------------- El ViewModel

    /** Un ViewModel del ecocardiograma con lo que ya está leído (el `old()` vacío, como la primera carga). */
    private function vista(array $valores = [], array $old = [], bool $hayFila = true): EcoCardiogramaFetalVista
    {
        return new EcoCardiogramaFetalVista(
            new EstudioEcografico($valores),
            '/guardar',
            '/volver',
            '/imprimir',
            null,
            null,
            $old,
            $hayFila,
        );
    }

    public function test_el_viewmodel_arma_los_bloques_con_el_valor_y_las_opciones_de_cada_campo(): void
    {
        $vm = $this->vista([
            'fecha' => '2026-10-07',
            'fur' => '2026-05-20',
            'gesta' => 2,
            'parto' => 2,
            'aborto' => 0,
            'cesarea' => 1,
            'otros' => 'Ninguno',
            'biome_dbp' => 25,
            'pla_funi_placenta' => 'A',
            'pla_funi_grado' => 'II',
            'conclusion' => 'Corazón estructuralmente normal.',
        ]);

        $this->assertSame('Ecocardiograma fetal', $vm->titulo());
        $this->assertSame(TiposDeEstudio::icono(TiposDeEstudio::FETAL), $vm->icono());
        $this->assertSame('/guardar', $vm->urlGuardar());
        $this->assertSame('/volver', $vm->urlVolver());
        $this->assertSame('/imprimir', $vm->urlImprimir());

        $grupos = $vm->grupos();
        $this->assertCount(6, $grupos);

        // La ficha, con sus valores (la gesta guarda el código y el navegador muestra el romano). La FUR se
        // guarda en `AAAA-MM-DD` y se muestra como se teclea, `dd/mm/aaaa` (O-18 y O-19).
        $this->assertSame('FUR', $grupos[0]['campos'][1]['etiqueta']);
        $this->assertSame('20/05/2026', $grupos[0]['campos'][1]['valor']);
        $this->assertSame('2', $grupos[0]['campos'][2]['valor']);
        $this->assertSame('1', $grupos[0]['campos'][5]['valor']);

        // El memo grande va sin rótulo (el DataWindow no se lo pone) y es un área.
        $this->assertSame('', $grupos[1]['campos'][0]['etiqueta']);
        $this->assertSame('texto_inicio', $grupos[1]['campos'][0]['columna']);
        $this->assertSame('area', $grupos[1]['campos'][0]['tipo']);

        // La biometría, con la unidad del mapa.
        $this->assertSame('25', $grupos[2]['campos'][0]['valor']);
        $this->assertSame('mm', $grupos[2]['campos'][0]['unidad']);

        // El memo anatómico, otra vez sin rótulo.
        $this->assertSame('', $grupos[3]['campos'][0]['etiqueta']);
        $this->assertSame('anatomi_datos', $grupos[3]['campos'][0]['columna']);

        // Los desplegables llegan con sus opciones resueltas, la vacía adelante y la elegida por valor.
        $placenta = $grupos[4]['campos'][0];
        $this->assertSame('A', $placenta['valor']);
        $this->assertSame(['', 'A', 'P', 'F', 'D', 'I', 'M'], array_column($placenta['opciones'], 'valor'));

        // Y la conclusión, con sus dos áreas.
        $this->assertSame('Signos ecográficos sugestivos de', $grupos[5]['campos'][0]['etiqueta']);
        $this->assertSame('Corazón estructuralmente normal.', $grupos[5]['campos'][0]['valor']);
    }

    public function test_el_viewmodel_muestra_lo_escrito_en_el_post_que_fallo(): void
    {
        $vm = $this->vista(['biome_dbp' => '25'], ['biome_dbp' => 'lo escrito']);

        $this->assertSame('lo escrito', $vm->grupos()[2]['campos'][0]['valor']);
    }

    /**
     * La pantalla abre una consulta sin fila con los dos textos que la ventana del escritorio precarga: el
     * ViewModel los muestra. Pero la hoja se arma de lo **guardado**: sin fila no se ofrece, porque el estudio
     * todavía no existe (la impresión daría 404).
     */
    public function test_el_viewmodel_muestra_la_precarga_de_los_dos_textos(): void
    {
        $vm = $this->vista(EcoCardiogramaFetal::precarga());

        $this->assertSame(EcoCardiogramaFetal::TEXTO_INICIAL, $vm->grupos()[1]['campos'][0]['valor']);
        $this->assertSame(EcoCardiogramaFetal::TEXTO_ANATOMICO, $vm->grupos()[3]['campos'][0]['valor']);

        // Los dos memos cuentan como datos del estudio: la precarga no deja el formulario vacío.
        $this->assertSame(2, $vm->cargados());
        $this->assertTrue($vm->esUnDocumento());

        // Sin fila (la consulta todavía no tiene estudio), la precarga se ve igual pero la hoja no se ofrece.
        $sinGuardar = $this->vista(EcoCardiogramaFetal::precarga(), [], false);
        $this->assertSame(EcoCardiogramaFetal::TEXTO_INICIAL, $sinGuardar->grupos()[1]['campos'][0]['valor']);
        $this->assertFalse($sinGuardar->esUnDocumento());
        $this->assertSame('Sin guardar', $sinGuardar->estado()['texto']);
    }

    public function test_la_ficha_no_cuenta_como_hallazgo_y_un_estudio_sin_datos_no_es_un_documento(): void
    {
        // Sólo la ficha (la fecha, la FUR, la gesta y la paridad): no hay nada del estudio.
        $vacio = $this->vista([
            'fecha' => '2026-10-07', 'fur' => '2026-05-20', 'gesta' => 2,
            'parto' => 2, 'aborto' => 0, 'cesarea' => 1, 'otros' => 'Ninguno',
        ]);
        $this->assertSame(0, $vacio->cargados());
        $this->assertSame(21, $vacio->totalCampos());
        $this->assertFalse($vacio->esUnDocumento());
        $this->assertSame('Sin cargar', $vacio->estado()['texto']);

        // Una sola medida del estudio ya lo es.
        $conUnaMedida = $this->vista(['biome_dbp' => 25]);
        $this->assertSame(1, $conUnaMedida->cargados());
        $this->assertTrue($conUnaMedida->esUnDocumento());
        $this->assertSame('1 de 21 campos', $conUnaMedida->estado()['texto']);

        // Un estudio de puro `none` del escritorio sigue siendo "sin datos".
        $this->assertFalse($this->vista(['conclusion' => 'none'])->esUnDocumento());
    }

    /**
     * La FPP y la EG del estudio son las del escritorio: la FPP suma **283** (`relativedate(fur, 283)`) y la EG
     * es la cuenta de siempre, las dos de `EdadGestacional` (no se reimplementan).
     */
    public function test_la_fpp_y_la_eg_salen_de_la_fur(): void
    {
        $vm = $this->vista(['fur' => '2026-05-20']);

        // La FUR + 283 días: del 20/05/2026 al 24/02/2027.
        $this->assertSame('24/02/2027', $vm->fpp());
        // Del 20/05/2026 al 07/10/2026 hay 140 días exactos: 20 semanas y 0 días.
        $this->assertSame('20 semanas y 0 días', $vm->edadGestacional());

        // Sin FUR no hay ninguna de las dos: no se inventan.
        $sinFur = $this->vista([]);
        $this->assertNull($sinFur->fpp());
        $this->assertNull($sinFur->edadGestacional());
    }
}
