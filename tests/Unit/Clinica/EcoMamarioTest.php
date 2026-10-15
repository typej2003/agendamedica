<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\Vistas\EcoMamarioVista;
use App\Clinica\Consulta\Vistas\OpcionesDelDesplegable;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa del dato y el ViewModel del eco mamario** (WEB-6.4), sin base de datos.
 *
 * Es el contrato del mapa con el resto del sistema: la pantalla, la Action, la hoja y la historia leen de
 * `EcoMamario`, así que una columna que se olvide de entrar (o una que entre dos veces) se ve acá y no en el
 * navegador. Lo que estos casos fijan:
 *
 *  - **Los tres bloques** del formulario y los **diez rótulos** del DataWindow vivo
 *    (`fuentes/ventana_consulta/d_ultasonido_mamas.srd`, que no está en `datawin_procesos/`: trampas 102, 123
 *    y 124), con los topes de sus cuatro campos cortos (`edit.limit`: 100, 20, 60 y 100).
 *  - **El desplegable de médicos** (`referido`, que guarda la cédula): el mapa lo declara con las opciones
 *    **vacías** y el ViewModel las completa con la libreta del médico, **con la opción vacía adelante**
 *    (trampa 112).
 *  - Que **`para` no está** en el mapa (va oculto en el DataWindow, nunca se imprime y el dump lo tiene
 *    siempre vacío) ni `puro_texto` (el informe en texto libre del escritorio, que se muestra y no se edita).
 *  - La **ficha** es sólo la fecha: no hay FUR ni gesta, así que un estudio con su fecha sola no es documento.
 */
class EcoMamarioTest extends TestCase
{
    // ------------------------------------------------------------------------------------- El mapa del dato

    public function test_los_bloques_son_los_tres_del_formulario(): void
    {
        $bloques = EcoMamario::grupos();

        $this->assertSame(
            ['El encabezado', 'Las mamas y las axilas', 'La conclusión'],
            array_column($bloques, 'titulo'),
        );

        // Ninguno es una grilla: el mamario no tiene tabla de medidas.
        $this->assertSame(
            [false, false, false],
            array_map(fn (array $bloque) => ($bloque['grilla'] ?? false) === true, $bloques),
        );
    }

    public function test_los_rotulos_y_los_topes_son_los_del_datawindow(): void
    {
        $campos = [];
        foreach (EcoMamario::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $campos[$campo['columna']] = $campo;
            }
        }

        // Los once campos del formulario, con el rótulo del escritorio (sin sus dos puntos: los agregan la
        // pantalla y la hoja).
        $this->assertSame(
            ['fecha', 'referido', 'tranductor', 'menarquia', 'quirurgicos', 'hormonas',
                'mama_derecha', 'axila_derecha', 'mama_izquierda', 'axila_izquierda', 'conclusion'],
            array_keys($campos),
        );

        foreach ([
            'fecha' => ['Fecha', 'fecha'],
            'referido' => ['Referido por', 'opciones'],
            // ⚠️ `tranductor` es el nombre real de la columna (typo del legado): no se renombra.
            'tranductor' => ['Transductor', 'texto'],
            'menarquia' => ['Menarquia', 'texto'],
            'quirurgicos' => ['Quirúrgicos', 'texto'],
            'hormonas' => ['Hormonas', 'texto'],
            'mama_derecha' => ['Mama derecha', 'area'],
            'axila_derecha' => ['Axila derecha', 'area'],
            'mama_izquierda' => ['Mama izquierda', 'area'],
            'axila_izquierda' => ['Axila izquierda', 'area'],
            'conclusion' => ['Conclusión', 'area'],
        ] as $columna => [$etiqueta, $tipo]) {
            $this->assertSame($etiqueta, $campos[$columna]['etiqueta'], "El rótulo de {$columna}.");
            $this->assertSame($tipo, $campos[$columna]['tipo'], "El tipo de {$columna}.");
        }

        // Los topes de los cuatro textos cortos, los del `edit.limit` del DataWindow.
        $this->assertSame(100, $campos['tranductor']['tope']);
        $this->assertSame(20, $campos['menarquia']['tope']);
        $this->assertSame(60, $campos['quirurgicos']['tope']);
        $this->assertSame(100, $campos['hormonas']['tope']);

        // `para` va oculto en el DataWindow y `puro_texto` es el informe del escritorio: ninguno está.
        $this->assertArrayNotHasKey('para', $campos);
        $this->assertArrayNotHasKey(EcoMamario::COLUMNA_PURO_TEXTO, $campos);
    }

    public function test_las_columnas_editables_son_once_y_no_se_repiten(): void
    {
        $columnas = EcoMamario::columnas();

        // 6 del encabezado + 4 de las mamas y las axilas + 1 de la conclusión.
        $this->assertCount(11, $columnas);
        $this->assertSame($columnas, array_values(array_unique($columnas)));

        // `puro_texto` NO está: es el informe en texto libre del escritorio, que se muestra y no se edita.
        $this->assertNotContains(EcoMamario::COLUMNA_PURO_TEXTO, $columnas);

        // Y la tabla y la clave son las del DataWindow: `historia` + `consulta`, no `numhistoria`.
        $this->assertSame('ultra_mama', EcoMamario::TABLA);
        $this->assertSame('historia', EcoMamario::COLUMNA_HISTORIA);
        $this->assertSame('consulta', EcoMamario::COLUMNA_CONSULTA);

        // La ficha es sólo la fecha: el mamario no es un eco del embarazo.
        $this->assertSame(['fecha'], EcoMamario::COLUMNAS_QUE_NO_CUENTAN);
    }

    public function test_el_referido_no_tiene_codigos_del_legado_y_lo_demas_tampoco(): void
    {
        // El único desplegable es el de médicos y el mapa lo deja vacío: sus opciones son la libreta del
        // médico y las arma el ViewModel. Ninguna columna tiene un código del DataWindow.
        $this->assertSame([], EcoMamario::opcionesDe('referido'));

        foreach (EcoMamario::columnas() as $columna) {
            $this->assertSame([], EcoMamario::opcionesDe($columna), "La columna {$columna} no es un código del legado.");
        }

        // El texto de un valor sin lista es el valor tal cual (la cédula del referido la resuelve la hoja).
        $this->assertSame('10432', EcoMamario::textoDe('referido', 10432));
        $this->assertNull(EcoMamario::textoDe('referido', 'none'));
        $this->assertSame('inventada', EcoMamario::etiquetaDe('inventada'));
    }

    // -------------------------------------------------------------------------------------- El ViewModel

    /**
     * Un ViewModel del mamario con lo que ya está leído (el `old()` vacío, como la primera carga).
     *
     * @param  array<int,array{valor:string,texto:string}>  $medicos
     */
    private function vista(array $valores = [], array $old = [], array $medicos = []): EcoMamarioVista
    {
        return new EcoMamarioVista(
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
            'referido' => 10432,
            'tranductor' => 'Lineal 7,5 MHz',
            'menarquia' => '12 años',
            'quirurgicos' => 'Apendicectomía',
            'hormonas' => 'Anticonceptivos',
            'mama_derecha' => 'Sin hallazgos.',
            'conclusion' => 'Estudio dentro de límites normales.',
        ]);

        $this->assertSame('Eco mamario', $vm->titulo());
        $this->assertSame(TiposDeEstudio::icono(TiposDeEstudio::MAMARIO), $vm->icono());
        $this->assertSame('/guardar', $vm->urlGuardar());
        $this->assertSame('/volver', $vm->urlVolver());
        $this->assertSame('/imprimir', $vm->urlImprimir());

        $grupos = $vm->grupos();
        $this->assertCount(3, $grupos);

        $encabezado = $grupos[0]['campos'];
        $this->assertSame('Fecha', $encabezado[0]['etiqueta']);
        $this->assertSame('2026-10-14', $encabezado[0]['valor']);
        $this->assertSame('Referido por', $encabezado[1]['etiqueta']);
        $this->assertSame('10432', $encabezado[1]['valor']);
        $this->assertSame('Lineal 7,5 MHz', $encabezado[2]['valor']);
        $this->assertSame('12 años', $encabezado[3]['valor']);
        $this->assertSame('Apendicectomía', $encabezado[4]['valor']);
        $this->assertSame('Anticonceptivos', $encabezado[5]['valor']);

        $this->assertSame('Mama derecha', $grupos[1]['campos'][0]['etiqueta']);
        $this->assertSame('Sin hallazgos.', $grupos[1]['campos'][0]['valor']);
        $this->assertSame('Conclusión', $grupos[2]['campos'][0]['etiqueta']);
    }

    /**
     * El desplegable de médicos: sus opciones son la libreta que le pasa el controlador, **con la opción vacía
     * adelante** (trampa 112) y el nombre del colegio como texto. Un `<select>` sin ninguna `selected` manda su
     * primera opción, así que sin la vacía el estudio nacería con un referido que nadie eligió.
     */
    public function test_el_selector_de_medicos_trae_su_opcion_vacia_adelante(): void
    {
        $medicos = [
            ['valor' => '10432', 'texto' => 'José Pérez'],
            ['valor' => '20877', 'texto' => 'María Rojas'],
        ];

        $vm = $this->vista(['referido' => 20877], [], $medicos);
        $referido = $vm->grupos()[0]['campos'][1];

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
        $vacio = $this->vista([], [], $medicos)->grupos()[0]['campos'][1];
        $this->assertSame('', $vacio['valor']);
        $this->assertSame(['', '10432', '20877'], array_column($vacio['opciones'], 'valor'));

        // Un código que el catálogo ya no tiene se conserva al final (no se pierde el dato del escritorio).
        $ajeno = $this->vista(['referido' => 99999], [], $medicos)->grupos()[0]['campos'][1];
        $this->assertSame(['', '10432', '20877', '99999'], array_column($ajeno['opciones'], 'valor'));
        $this->assertSame('99999 (del escritorio)', $ajeno['opciones'][3]['texto']);
    }

    public function test_el_viewmodel_muestra_lo_escrito_en_el_post_que_fallo(): void
    {
        $vm = $this->vista(['conclusion' => 'lo guardado'], ['conclusion' => 'lo escrito']);

        $this->assertSame('lo escrito', $vm->grupos()[2]['campos'][0]['valor']);
    }

    public function test_los_diez_campos_cuentan_y_la_fecha_sola_no_es_un_documento(): void
    {
        // Sólo la ficha: la fecha no cuenta como hallazgo, así que no se guarda ni se ofrece la hoja.
        $vacio = $this->vista(['fecha' => '2026-10-14']);
        $this->assertSame(0, $vacio->cargados());
        $this->assertSame(10, $vacio->totalCampos());
        $this->assertFalse($vacio->esUnDocumento());
        $this->assertSame('Sin cargar', $vacio->estado()['texto']);

        // Cualquiera de los diez campos del estudio ya lo es (acá, el transductor).
        $conUno = $this->vista(['tranductor' => 'Lineal 7,5 MHz']);
        $this->assertSame(1, $conUno->cargados());
        $this->assertTrue($conUno->esUnDocumento());
        $this->assertSame('1 de 10 campos', $conUno->estado()['texto']);

        // Cargar los diez da el total.
        $lleno = $this->vista([
            'fecha' => '2026-10-14', 'referido' => 10432, 'tranductor' => 'Lineal', 'menarquia' => '12',
            'quirurgicos' => 'Apendicectomía', 'hormonas' => 'Ninguna', 'mama_derecha' => 'A',
            'axila_derecha' => 'B', 'mama_izquierda' => 'C', 'axila_izquierda' => 'D', 'conclusion' => 'E',
        ]);
        $this->assertSame(10, $lleno->cargados());
        $this->assertSame('10 de 10 campos', $lleno->estado()['texto']);

        // Un estudio de puro `none` del escritorio sigue siendo "sin datos".
        $this->assertFalse($this->vista(['conclusion' => 'none'])->esUnDocumento());
    }

    public function test_el_informe_en_texto_libre_del_escritorio_se_muestra_y_no_se_edita(): void
    {
        $sinTexto = $this->vista(['conclusion' => 'Normal']);
        $this->assertNull($sinTexto->puroTexto());
        $this->assertFalse($sinTexto->hayPuroTexto());

        // `puro_texto` no está entre las columnas que la pantalla edita: se muestra y no se toca.
        $conTexto = $this->vista([EcoMamario::COLUMNA_PURO_TEXTO => "INFORME DEL ESCRITORIO\nSegunda línea."]);
        $this->assertTrue($conTexto->hayPuroTexto());
        $this->assertSame("INFORME DEL ESCRITORIO\nSegunda línea.", $conTexto->puroTexto());
        $this->assertNotContains(EcoMamario::COLUMNA_PURO_TEXTO, EcoMamario::columnas());

        // ⚠️ Pero **no** hace de la fila un documento: la hoja del mamario no tiene párrafos de texto libre (su
        // modo «Plantillas» es otro DataWindow de impresión, que la web no imprime), así que una fila que sólo
        // trae `puro_texto` se muestra sin ofrecer un informe vacío. A diferencia del eco pélvico y el Doppler.
        $this->assertSame(0, $conTexto->cargados());
        $this->assertFalse($conTexto->esUnDocumento());
        $this->assertSame('Sin cargar', $conTexto->estado()['texto']);
    }

    /** La opción vacía adelante la pone el ViewModel con la misma regla que los otros desplegables. */
    public function test_la_opcion_vacia_la_antepone_la_regla_compartida(): void
    {
        $medicos = [['valor' => '10432', 'texto' => 'José Pérez']];

        $this->assertSame(
            ['', '10432'],
            array_column(OpcionesDelDesplegable::de(['opciones' => $medicos], ''), 'valor'),
        );
    }
}
