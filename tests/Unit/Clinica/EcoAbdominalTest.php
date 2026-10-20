<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\Vistas\EcoAbdominalVista;
use App\Clinica\Consulta\Vistas\OpcionesDelDesplegable;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa del dato y el ViewModel del eco abdominal** (WEB-6.4), sin base de datos.
 *
 * Es el contrato del mapa con el resto del sistema: la pantalla, la Action, la hoja y la historia leen de
 * `EcoAbdominal`, así que una columna que se olvide de entrar (o una que entre dos veces) se ve acá y no en el
 * navegador. Lo que estos casos fijan:
 *
 *  - **Los cuatro bloques** del formulario y los **quince rótulos** del DataWindow vivo
 *    (`fuentes/ventana_consulta/d_ultasonido_abdominal.srd`, que no está en `datawin_procesos/`: trampas 102,
 *    123 y 124), con el tope del único campo corto (`edit.limit`: el transductor, 30).
 *  - **Los cuatro desplegables con códigos** (`vol_res_v_p_m` y `ascitis` con `Si→N`/`No→A`, o sea **al revés**
 *    de las casillas Sí/No de los obstétricos; y `retroperitoneo` y `vasos_sanguineos` con `Normal→N`/
 *    `Anormal→A`), con la **opción vacía adelante** (trampa 112).
 *  - **El desplegable de médicos** (`referido`, que guarda la cédula): el mapa lo declara con las opciones
 *    **vacías** y el ViewModel las completa con la libreta del médico.
 *  - Que **`prostata` y `vejiga_urina` no están** en el mapa (son de urología y en ginecología van ocultas) ni
 *    `puro_texto` (el informe en texto libre del escritorio, que se muestra y no se edita).
 *  - La **ficha** es sólo la fecha: un estudio con su fecha sola no es documento.
 */
class EcoAbdominalTest extends TestCase
{
    // ------------------------------------------------------------------------------------- El mapa del dato

    public function test_los_bloques_son_los_cuatro_del_formulario(): void
    {
        $bloques = EcoAbdominal::grupos();

        $this->assertSame(
            ['El encabezado', 'Los órganos', 'El resto del abdomen', 'Las observaciones y las conclusiones'],
            array_column($bloques, 'titulo'),
        );

        // Ninguno es una grilla: el abdominal no tiene tabla de medidas.
        $this->assertSame(
            [false, false, false, false],
            array_map(fn (array $bloque) => ($bloque['grilla'] ?? false) === true, $bloques),
        );
    }

    public function test_los_rotulos_y_el_tope_son_los_del_datawindow(): void
    {
        $campos = [];
        foreach (EcoAbdominal::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $campos[$campo['columna']] = $campo;
            }
        }

        // Los quince campos del formulario, en el orden del DataWindow vivo (el mismo del formulario y del
        // informe), con el rótulo del escritorio (sin sus dos puntos: los agregan la pantalla y la hoja).
        $this->assertSame(
            ['fecha', 'transductor', 'referido', 'higado', 'vesicula_bilial', 'porta_coledoco', 'pancreas',
                'rinon_derecho', 'rinon_izquierdo', 'vol_res_v_p_m', 'ascitis', 'retroperitoneo',
                'vasos_sanguineos', 'otros', 'conclusiones'],
            array_keys($campos),
        );

        foreach ([
            'fecha' => ['Fecha', 'fecha'],
            // ⚠️ Acá la columna es `transductor` (a diferencia del `tranductor` con el typo del eco mamario).
            'transductor' => ['Transductor', 'texto'],
            'referido' => ['Referido por', 'opciones'],
            'higado' => ['Hígado', 'area'],
            'vesicula_bilial' => ['Vesícula biliar', 'area'],
            'porta_coledoco' => ['Porta y colédoco', 'area'],
            'pancreas' => ['Páncreas', 'area'],
            'rinon_derecho' => ['Rinón derecho', 'area'],
            'rinon_izquierdo' => ['Rinón izquierdo', 'area'],
            'vol_res_v_p_m' => ['Volumen residual vesical Post-Micción', 'opciones'],
            'ascitis' => ['Ascitis', 'opciones'],
            'retroperitoneo' => ['Retroperitoneo', 'opciones'],
            'vasos_sanguineos' => ['Vasos sanguíneos', 'opciones'],
            // ⚠️ La columna `otros` se rotula «Otros» en el formulario y «Observaciones» en la hoja.
            'otros' => ['Otros', 'area'],
            'conclusiones' => ['Conclusiones', 'area'],
        ] as $columna => [$etiqueta, $tipo]) {
            $this->assertSame($etiqueta, $campos[$columna]['etiqueta'], "El rótulo de {$columna}.");
            $this->assertSame($tipo, $campos[$columna]['tipo'], "El tipo de {$columna}.");
        }

        // El único tope del `edit.limit` del DataWindow: el transductor es un `char(30)`.
        $this->assertSame(30, $campos['transductor']['tope']);

        // `prostata` y `vejiga_urina` van ocultas en ginecología y `puro_texto` es del escritorio: ninguno está.
        foreach (array_merge(EcoAbdominal::COLUMNAS_DE_UROLOGIA, [EcoAbdominal::COLUMNA_PURO_TEXTO]) as $columna) {
            $this->assertArrayNotHasKey($columna, $campos, "La columna {$columna} no debería estar en el mapa.");
        }
    }

    public function test_las_columnas_editables_son_quince_y_no_se_repiten(): void
    {
        $columnas = EcoAbdominal::columnas();

        // 3 del encabezado + 6 de los órganos + 4 del resto + 2 de las observaciones y conclusiones.
        $this->assertCount(15, $columnas);
        $this->assertSame($columnas, array_values(array_unique($columnas)));

        // Las de urología NO están: no se ofrecen, no se imprimen y la Action no las toca.
        foreach (EcoAbdominal::COLUMNAS_DE_UROLOGIA as $columna) {
            $this->assertNotContains($columna, $columnas);
        }
        $this->assertSame(['prostata', 'vejiga_urina'], EcoAbdominal::COLUMNAS_DE_UROLOGIA);

        // `puro_texto` tampoco: es el informe en texto libre del escritorio, que se muestra y no se edita.
        $this->assertNotContains(EcoAbdominal::COLUMNA_PURO_TEXTO, $columnas);

        // Y la tabla y la clave son las del DataWindow: `historia` + `consulta`, no `numhistoria`.
        $this->assertSame('ultra_abdominal', EcoAbdominal::TABLA);
        $this->assertSame('historia', EcoAbdominal::COLUMNA_HISTORIA);
        $this->assertSame('consulta', EcoAbdominal::COLUMNA_CONSULTA);

        // La ficha es sólo la fecha: el abdominal no es un eco del embarazo (ni tiene FUR ni gesta).
        $this->assertSame(['fecha'], EcoAbdominal::COLUMNAS_QUE_NO_CUENTAN);
    }

    /**
     * Los cuatro desplegables con los códigos del DataWindow. Los dos de Sí/No guardan **`N`/`A`** (Sí→N,
     * No→A), al revés de las casillas Sí/No de los obstétricos (que guardan `S`/`N`): es la trampa del legado
     * que hay que respetar para no invertir el dato al guardar.
     */
    public function test_los_cuatro_desplegables_tienen_los_codigos_del_datawindow(): void
    {
        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Sí'], ['valor' => 'A', 'texto' => 'No']],
            EcoAbdominal::opcionesDe('vol_res_v_p_m'),
        );
        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Sí'], ['valor' => 'A', 'texto' => 'No']],
            EcoAbdominal::opcionesDe('ascitis'),
        );
        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Normal'], ['valor' => 'A', 'texto' => 'Anormal']],
            EcoAbdominal::opcionesDe('retroperitoneo'),
        );
        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Normal'], ['valor' => 'A', 'texto' => 'Anormal']],
            EcoAbdominal::opcionesDe('vasos_sanguineos'),
        );

        // El texto que va a la hoja traduce el código; un código que no está en la lista se conserva tal cual.
        $this->assertSame('Sí', EcoAbdominal::textoDe('ascitis', 'N'));
        $this->assertSame('No', EcoAbdominal::textoDe('ascitis', 'A'));
        $this->assertSame('Anormal', EcoAbdominal::textoDe('vasos_sanguineos', 'A'));
        $this->assertSame('Normal', EcoAbdominal::textoDe('retroperitoneo', 'N'));
        $this->assertSame('Z', EcoAbdominal::textoDe('ascitis', 'Z'));
        $this->assertNull(EcoAbdominal::textoDe('ascitis', 'none'));
    }

    public function test_el_unico_desplegable_sin_codigos_es_el_de_medicos(): void
    {
        // El referido guarda la cédula de un colega: el mapa lo deja vacío y el ViewModel pone la libreta.
        $this->assertSame([], EcoAbdominal::opcionesDe('referido'));

        // El texto de un valor sin lista es el valor tal cual (la cédula la resuelve la hoja).
        $this->assertSame('10432', EcoAbdominal::textoDe('referido', 10432));
        $this->assertSame('inventada', EcoAbdominal::etiquetaDe('inventada'));
    }

    // -------------------------------------------------------------------------------------- El ViewModel

    /**
     * Un ViewModel del abdominal con lo que ya está leído (el `old()` vacío, como la primera carga).
     *
     * @param  array<int,array{valor:string,texto:string}>  $medicos
     */
    private function vista(array $valores = [], array $old = [], array $medicos = []): EcoAbdominalVista
    {
        return new EcoAbdominalVista(
            new EstudioEcografico($valores),
            '/guardar',
            '/volver',
            '/imprimir',
            $medicos,
            $old,
        );
    }

    public function test_el_viewmodel_arma_los_bloques_con_el_valor_de_cada_campo(): void
    {
        $vm = $this->vista([
            'fecha' => '2026-10-14',
            'transductor' => 'Convexo 3,5 MHz',
            'referido' => 10432,
            'higado' => 'De tamaño y ecogenicidad normales.',
            'vesicula_bilial' => 'Sin litiasis.',
            'ascitis' => 'A',
            'conclusiones' => 'Estudio dentro de límites normales.',
        ]);

        $this->assertSame('Eco abdominal', $vm->titulo());
        $this->assertSame(TiposDeEstudio::icono(TiposDeEstudio::ABDOMINAL), $vm->icono());
        $this->assertSame('/guardar', $vm->urlGuardar());
        $this->assertSame('/volver', $vm->urlVolver());
        $this->assertSame('/imprimir', $vm->urlImprimir());

        $grupos = $vm->grupos();
        $this->assertCount(4, $grupos);

        $encabezado = $grupos[0]['campos'];
        $this->assertSame('Fecha', $encabezado[0]['etiqueta']);
        $this->assertSame('2026-10-14', $encabezado[0]['valor']);
        $this->assertSame('Transductor', $encabezado[1]['etiqueta']);
        $this->assertSame('Convexo 3,5 MHz', $encabezado[1]['valor']);
        $this->assertSame('Referido por', $encabezado[2]['etiqueta']);
        $this->assertSame('10432', $encabezado[2]['valor']);

        $this->assertSame('Hígado', $grupos[1]['campos'][0]['etiqueta']);
        $this->assertSame('De tamaño y ecogenicidad normales.', $grupos[1]['campos'][0]['valor']);
        $this->assertSame('Ascitis', $grupos[2]['campos'][1]['etiqueta']);
        $this->assertSame('A', $grupos[2]['campos'][1]['valor']);
        $this->assertSame('Conclusiones', $grupos[3]['campos'][1]['etiqueta']);
    }

    /**
     * Los cuatro desplegables con códigos salen con la **opción vacía adelante** (trampa 112): un `<select>`
     * sin ninguna `selected` manda su primera opción, así que sin la vacía un estudio recién abierto guardaría
     * «Sí» o «Normal» en las cuatro casillas.
     */
    public function test_los_desplegables_traen_su_opcion_vacia_adelante(): void
    {
        $vm = $this->vista(['ascitis' => 'A', 'retroperitoneo' => 'Z']);

        $ascitis = $vm->grupos()[2]['campos'][1];
        $this->assertSame('A', $ascitis['valor']);
        $this->assertSame(
            [
                ['valor' => '', 'texto' => '—'],
                ['valor' => 'N', 'texto' => 'Sí'],
                ['valor' => 'A', 'texto' => 'No'],
            ],
            $ascitis['opciones'],
        );

        // Recién abierto: la vacía queda elegida (el navegador no inventa un hallazgo).
        $vacio = $this->vista([])->grupos()[2]['campos'][1];
        $this->assertSame('', $vacio['valor']);
        $this->assertSame(['', 'N', 'A'], array_column($vacio['opciones'], 'valor'));

        // Un código que el DataWindow no tiene se conserva al final (no se pierde el dato del escritorio).
        $ajeno = $vm->grupos()[2]['campos'][2];
        $this->assertSame(['', 'N', 'A', 'Z'], array_column($ajeno['opciones'], 'valor'));
        $this->assertSame('Z (valor anterior)', $ajeno['opciones'][3]['texto']);
    }

    /**
     * El desplegable de médicos: sus opciones son la libreta que le pasa el controlador, **con la opción vacía
     * adelante** (trampa 112) y el nombre del colegio como texto.
     */
    public function test_el_selector_de_medicos_trae_su_opcion_vacia_adelante(): void
    {
        $medicos = [
            ['valor' => '10432', 'texto' => 'José Pérez'],
            ['valor' => '20877', 'texto' => 'María Rojas'],
        ];

        $vm = $this->vista(['referido' => 20877], [], $medicos);
        $referido = $vm->grupos()[0]['campos'][2];

        $this->assertSame('20877', $referido['valor']);
        $this->assertSame('opciones', $referido['tipo']);
        $this->assertSame(
            [
                ['valor' => '', 'texto' => '—'],
                ['valor' => '10432', 'texto' => 'José Pérez'],
                ['valor' => '20877', 'texto' => 'María Rojas'],
            ],
            $referido['opciones'],
        );

        // Recién abierto: la vacía queda elegida (el navegador no inventa un referido).
        $vacio = $this->vista([], [], $medicos)->grupos()[0]['campos'][2];
        $this->assertSame('', $vacio['valor']);
        $this->assertSame(['', '10432', '20877'], array_column($vacio['opciones'], 'valor'));

        // Un código que el catálogo ya no tiene se conserva al final (no se pierde el dato del escritorio).
        $ajeno = $this->vista(['referido' => 99999], [], $medicos)->grupos()[0]['campos'][2];
        $this->assertSame(['', '10432', '20877', '99999'], array_column($ajeno['opciones'], 'valor'));
    }

    public function test_el_viewmodel_muestra_lo_escrito_en_el_post_que_fallo(): void
    {
        $vm = $this->vista(['conclusiones' => 'lo guardado'], ['conclusiones' => 'lo escrito']);

        $this->assertSame('lo escrito', $vm->grupos()[3]['campos'][1]['valor']);
    }

    /**
     * **`prostata` y `vejiga_urina` no se ofrecen**: no están en el mapa ni en el formulario. La decisión es del
     * usuario (2026-10-14): son de urología y en ginecología van ocultas en el escritorio.
     */
    public function test_la_pantalla_no_ofrece_prostata_ni_vejiga_urinaria(): void
    {
        $vm = $this->vista(['higado' => 'Normal']);

        $columnas = [];
        foreach ($vm->grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $columnas[] = $campo['columna'];
            }
        }

        $this->assertNotContains('prostata', $columnas);
        $this->assertNotContains('vejiga_urina', $columnas);
        // Y no se cuentan entre los campos del estudio (los 14 sin la fecha).
        $this->assertNotContains('prostata', EcoAbdominal::columnas());
        $this->assertNotContains('vejiga_urina', EcoAbdominal::columnas());
    }

    public function test_los_catorce_campos_cuentan_y_la_fecha_sola_no_es_un_documento(): void
    {
        // Sólo la ficha: la fecha no cuenta como hallazgo, así que no se guarda ni se ofrece la hoja.
        $vacio = $this->vista(['fecha' => '2026-10-14']);
        $this->assertSame(0, $vacio->cargados());
        $this->assertSame(14, $vacio->totalCampos());
        $this->assertFalse($vacio->esUnDocumento());
        $this->assertSame('Sin cargar', $vacio->estado()['texto']);

        // Cualquiera de los catorce campos del estudio ya lo es (acá, el transductor).
        $conUno = $this->vista(['transductor' => 'Convexo 3,5 MHz']);
        $this->assertSame(1, $conUno->cargados());
        $this->assertTrue($conUno->esUnDocumento());
        $this->assertSame('1 de 14 campos', $conUno->estado()['texto']);

        // Un dropdown con su código también cuenta.
        $this->assertSame(1, $this->vista(['ascitis' => 'N'])->cargados());

        // Un estudio de puro `none` del escritorio sigue siendo "sin datos".
        $this->assertFalse($this->vista(['conclusiones' => 'none'])->esUnDocumento());
    }

    public function test_el_informe_en_texto_libre_del_escritorio_se_muestra_y_no_se_edita(): void
    {
        $sinTexto = $this->vista(['conclusiones' => 'Normal']);
        $this->assertNull($sinTexto->puroTexto());
        $this->assertFalse($sinTexto->hayPuroTexto());

        // `puro_texto` no está entre las columnas que la pantalla edita: se muestra y no se toca.
        $conTexto = $this->vista([EcoAbdominal::COLUMNA_PURO_TEXTO => "INFORME DEL ESCRITORIO\nSegunda línea."]);
        $this->assertTrue($conTexto->hayPuroTexto());
        $this->assertSame("INFORME DEL ESCRITORIO\nSegunda línea.", $conTexto->puroTexto());
        $this->assertNotContains(EcoAbdominal::COLUMNA_PURO_TEXTO, EcoAbdominal::columnas());

        // ⚠️ Pero **no** hace de la fila un documento: la hoja del abdominal no tiene párrafos de texto libre
        // (su modo «Plantillas» es otro DataWindow de impresión, que la web no imprime).
        $this->assertSame(0, $conTexto->cargados());
        $this->assertFalse($conTexto->esUnDocumento());
        $this->assertSame('Sin cargar', $conTexto->estado()['texto']);
    }

    /** La opción vacía adelante la pone el ViewModel con la misma regla que los otros desplegables. */
    public function test_la_opcion_vacia_la_antepone_la_regla_compartida(): void
    {
        $this->assertSame(
            ['', 'N', 'A'],
            array_column(OpcionesDelDesplegable::de(['opciones' => EcoAbdominal::opcionesDe('ascitis')], ''), 'valor'),
        );
    }
}
