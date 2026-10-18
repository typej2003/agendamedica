<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\Vistas\EcoDopplerVista;
use App\Clinica\Consulta\Vistas\OpcionesDelDesplegable;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa del dato y el ViewModel del eco Doppler perinatal** (WEB-6.3), sin base de datos.
 *
 * Es el contrato del mapa con el resto del sistema: la pantalla, la Action, la hoja y la historia leen de
 * `EcoDoppler`, así que una columna que se olvide de entrar (o una que entre dos veces) se ve acá y no en el
 * navegador. Lo que estos casos fijan:
 *
 *  - **Los cuatro bloques** del formulario, en el orden del DataWindow vivo (`fuentes/ventana_consulta/`
 *    `d_eco_doppler.srd`: no está en `datawin_procesos/`, trampas 102 y 123), con los rótulos del escritorio.
 *  - **La grilla de cuatro vasos**: cuatro filas (las arterias) por cuatro ejes, con la columna que le toca a
 *    cada celda.
 *  - **Los códigos de los desplegables** del legado —`ven_umbilical` guarda `N` para *Sí* y `A` para *No*, al
 *    revés de lo que parece— y, sobre todo, **la opción vacía adelante** (trampa 112).
 *  - La **ficha** (fecha, FUR, gesta y `para`) no cuenta como hallazgo: es lo que deciden la pastilla de
 *    estado, la Action y la hoja.
 */
class EcoDopplerTest extends TestCase
{
    // ------------------------------------------------------------------------------------- El mapa del dato

    public function test_los_bloques_son_los_cuatro_del_formulario_y_el_de_la_grilla_esta_marcado(): void
    {
        $bloques = EcoDoppler::grupos();

        $this->assertSame(
            ['Ficha', 'Eco Doppler Obstétrico en 4 Vasos', 'El flujo venoso y la aorta',
                'Las observaciones y la conclusión'],
            array_column($bloques, 'titulo'),
        );

        // El bloque de la grilla es el único marcado (es lo que la pantalla pinta como tabla).
        $this->assertSame(
            [false, true, false, false],
            array_map(fn (array $bloque) => ($bloque['grilla'] ?? false) === true, $bloques),
        );

        // Y los cuatro se titulan como en el DataWindow de impresión del escritorio.
        $this->assertSame('Eco Doppler Obstétrico en 4 Vasos', $bloques[1]['titulo']);
    }

    public function test_las_columnas_editables_son_veintiseis_y_no_se_repiten(): void
    {
        $columnas = EcoDoppler::columnas();

        // 4 de la ficha + 16 de la grilla + 3 desplegables + 3 áreas de texto.
        $this->assertCount(26, $columnas);
        $this->assertSame($columnas, array_values(array_unique($columnas)));

        // `puro_texto` NO está: es el informe en texto libre del escritorio, que se muestra y no se edita.
        $this->assertNotContains(EcoDoppler::COLUMNA_PURO_TEXTO, $columnas);

        // Y la tabla y la clave son las del DataWindow: `historia` + `consulta`, no `numhistoria`.
        $this->assertSame('eco_doppler', EcoDoppler::TABLA);
        $this->assertSame('historia', EcoDoppler::COLUMNA_HISTORIA);
        $this->assertSame('consulta', EcoDoppler::COLUMNA_CONSULTA);
    }

    public function test_la_grilla_son_cuatro_vasos_por_cuatro_ejes_con_su_columna(): void
    {
        $grilla = EcoDoppler::grilla();

        $this->assertSame(
            ['Arteria Uterina Der.', 'Arteria Uterina Izq.', 'Arteria Umbilical', 'Arteria Cerebral Media'],
            array_column($grilla, 'etiqueta'),
        );
        $this->assertSame(
            ['Índice de Pulsatibilidad', 'Índice de Resistencia', 'Relación S / D', 'Pico de Velocidad Sistólica'],
            EcoDoppler::ejesDeLaGrilla(),
        );

        // Las 16 celdas, con la columna del DataWindow de cada una (16 = 4 filas × 4 ejes).
        $celdas = [];
        foreach ($grilla as $fila) {
            $this->assertCount(4, $fila['campos']);

            foreach ($fila['campos'] as $campo) {
                $this->assertSame('texto', $campo['tipo'], 'El escritorio guarda los milímetros como texto.');
                $celdas[$campo['fila'] . '|' . $campo['eje']] = $campo['columna'];
            }
        }

        $this->assertCount(16, $celdas);
        $this->assertSame('arteria_uraina_menor', $celdas['Arteria Uterina Der.|Índice de Pulsatibilidad']);
        $this->assertSame('arteria_uraina_mayor', $celdas['Arteria Uterina Der.|Índice de Resistencia']);
        $this->assertSame('relacion_ud', $celdas['Arteria Uterina Der.|Relación S / D']);
        $this->assertSame('pico_ud', $celdas['Arteria Uterina Der.|Pico de Velocidad Sistólica']);
        // El nombre del legado de la uterina izquierda repite el error de tipeo `uraina` (trampa 124).
        $this->assertSame('arteria_uraina_menor_izq', $celdas['Arteria Uterina Izq.|Índice de Pulsatibilidad']);
        $this->assertSame('arteria_umbilical_mayor', $celdas['Arteria Umbilical|Índice de Resistencia']);
        $this->assertSame('relacion_cm', $celdas['Arteria Cerebral Media|Relación S / D']);
        $this->assertSame('pico_cm', $celdas['Arteria Cerebral Media|Pico de Velocidad Sistólica']);
    }

    public function test_los_codigos_de_los_desplegables_son_los_del_datawindow(): void
    {
        // Ductus venoso: Trifásico con Reversa → N y sin Reversa → S (el `values` del DataWindow).
        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Trifásico con Reversa'], ['valor' => 'S', 'texto' => 'Trifásico sin Reversa']],
            EcoDoppler::opcionesDe('ductos_venosos'),
        );

        // ⚠️ La señal venoso umbilical guarda los códigos al revés de lo que parece: Sí → N y No → A.
        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Sí'], ['valor' => 'A', 'texto' => 'No']],
            EcoDoppler::opcionesDe('ven_umbilical'),
        );

        $this->assertSame(
            [['valor' => 'N', 'texto' => 'Normal'], ['valor' => 'A', 'texto' => 'Anormal']],
            EcoDoppler::opcionesDe('relac_aorta'),
        );

        // La gesta: los números romanos I a XII con el código 1..12.
        $gesta = EcoDoppler::opcionesDe('gesta');
        $this->assertCount(12, $gesta);
        $this->assertSame(['valor' => '1', 'texto' => 'I'], $gesta[0]);
        $this->assertSame(['valor' => '12', 'texto' => 'XII'], $gesta[11]);

        // El texto que se muestra y el valor crudo del escritorio, cuando el código no está en la lista.
        $this->assertSame('Trifásico con Reversa', EcoDoppler::textoDe('ductos_venosos', 'N'));
        $this->assertSame('No', EcoDoppler::textoDe('ven_umbilical', 'A'));
        $this->assertSame('Z', EcoDoppler::textoDe('ductos_venosos', 'Z'));
        $this->assertNull(EcoDoppler::textoDe('ductos_venosos', 'none'));

        // Una columna que no está en el mapa no inventa rótulo ni opciones.
        $this->assertSame([], EcoDoppler::opcionesDe('inventada'));
        $this->assertSame('inventada', EcoDoppler::etiquetaDe('inventada'));
    }

    public function test_la_opcion_vacia_va_adelante_y_el_codigo_desconocido_al_final(): void
    {
        $codigos = fn (string $columna, string $valor) => array_column(
            OpcionesDelDesplegable::de(['opciones' => EcoDoppler::opcionesDe($columna)], $valor),
            'valor',
        );

        // La vacía primero: sin ella el navegador manda la primera opción y el estudio nace con un hallazgo
        // que nadie escribió (trampa 112).
        $this->assertSame(['', 'N', 'S'], $codigos('ductos_venosos', ''));
        $this->assertSame(['', 'N', 'A'], $codigos('ven_umbilical', ''));
        $this->assertSame(['', 'N', 'A'], $codigos('relac_aorta', ''));
        $this->assertSame(
            array_merge([''], array_map('strval', range(1, 12))),
            $codigos('gesta', ''),
        );

        // El código guardado que el escritorio no declara se conserva al final (no se traduce ni se pierde).
        $this->assertSame(['', 'N', 'S', 'Z'], $codigos('ductos_venosos', 'Z'));
        $this->assertSame(['', 'N', 'A', 'S'], $codigos('ven_umbilical', 'S'));
    }

    // -------------------------------------------------------------------------------------- El ViewModel

    /** Un ViewModel del Doppler con lo que ya está leído (el `old()` vacío, como la primera carga). */
    private function vista(array $valores = [], array $old = []): EcoDopplerVista
    {
        return new EcoDopplerVista(
            new EstudioEcografico($valores),
            '/guardar',
            '/volver',
            '/imprimir',
            null,
            null,
            $old,
        );
    }

    public function test_el_viewmodel_arma_los_bloques_con_el_valor_y_las_opciones_de_cada_campo(): void
    {
        $vm = $this->vista([
            'fur' => '2026-05-20',
            'gesta' => 2,
            'para' => '1 cesárea',
            'arteria_uraina_menor' => '0.85',
            'ductos_venosos' => 'S',
            'ven_umbilical' => 'N',
            'sugerencias' => 'Control en 4 semanas.',
        ]);

        $this->assertSame('Eco Doppler perinatal', $vm->titulo());
        $this->assertSame(TiposDeEstudio::icono(TiposDeEstudio::DOPPLER), $vm->icono());
        $this->assertSame('/guardar', $vm->urlGuardar());
        $this->assertSame('/volver', $vm->urlVolver());
        $this->assertSame('/imprimir', $vm->urlImprimir());

        $grupos = $vm->grupos();
        $this->assertCount(4, $grupos);

        // La ficha, con sus valores (la gesta guarda el código y muestra el romano).
        $this->assertNull($grupos[0]['grilla']);
        $this->assertSame('FUR', $grupos[0]['campos'][1]['etiqueta']);
        $this->assertSame('2026-05-20', $grupos[0]['campos'][1]['valor']);
        $this->assertSame('2', $grupos[0]['campos'][2]['valor']);
        $this->assertSame('1 cesárea', $grupos[0]['campos'][3]['valor']);

        // La grilla se arma por fila y por eje, con el valor de cada celda.
        $this->assertCount(4, $grupos[1]['grilla']['filas']);
        $this->assertSame('Arteria Uterina Der.', $grupos[1]['grilla']['filas'][0]['etiqueta']);
        $this->assertSame('0.85', $grupos[1]['grilla']['filas'][0]['campos'][0]['valor']);
        $this->assertSame('Índice de Pulsatibilidad', $grupos[1]['grilla']['filas'][0]['campos'][0]['eje']);
        $this->assertCount(4, $grupos[1]['grilla']['ejes']);

        // Los desplegables llegan con sus opciones resueltas, la vacía adelante y la elegida marcada por valor.
        $ductos = $grupos[2]['campos'][0];
        $this->assertSame('S', $ductos['valor']);
        $this->assertSame(['', 'N', 'S'], array_column($ductos['opciones'], 'valor'));

        // El memo grande va sin rótulo (el DataWindow no se lo pone) y las dos áreas del final con el suyo.
        $this->assertSame('', $grupos[3]['campos'][0]['etiqueta']);
        $this->assertSame('texto', $grupos[3]['campos'][0]['columna']);
        $this->assertSame('Observaciones', $grupos[3]['campos'][1]['etiqueta']);
        $this->assertSame('Conclusiones', $grupos[3]['campos'][2]['etiqueta']);
        $this->assertSame('Control en 4 semanas.', $grupos[3]['campos'][1]['valor']);
    }

    public function test_el_viewmodel_muestra_lo_escrito_en_el_post_que_fallo(): void
    {
        $vm = $this->vista(['para' => 'lo guardado'], ['para' => 'lo escrito']);

        $this->assertSame('lo escrito', $vm->grupos()[0]['campos'][3]['valor']);
    }

    public function test_la_ficha_no_cuenta_como_hallazgo_y_un_estudio_sin_datos_no_es_un_documento(): void
    {
        // Sólo la ficha: no hay nada del estudio, así que no se guarda ni se ofrece la hoja.
        $vacio = $this->vista(['fecha' => '2026-10-14', 'fur' => '2026-05-20', 'gesta' => 2, 'para' => '2 partos']);
        $this->assertSame(0, $vacio->cargados());
        $this->assertSame(22, $vacio->totalCampos());
        $this->assertFalse($vacio->esUnDocumento());
        $this->assertSame('Sin cargar', $vacio->estado()['texto']);

        // Una sola medida del estudio ya lo es.
        $conUnaMedida = $this->vista(['pico_cm' => '45']);
        $this->assertSame(1, $conUnaMedida->cargados());
        $this->assertTrue($conUnaMedida->esUnDocumento());
        $this->assertSame('1 de 22 campos', $conUnaMedida->estado()['texto']);

        // Un estudio de puro `none` del escritorio sigue siendo "sin datos".
        $this->assertFalse($this->vista(['conclusion' => 'none'])->esUnDocumento());
    }

    public function test_el_informe_en_texto_libre_del_escritorio_se_muestra_y_no_se_edita(): void
    {
        $sinTexto = $this->vista(['arteria_umbilical_menor' => '0.9']);
        $this->assertNull($sinTexto->puroTexto());
        $this->assertFalse($sinTexto->hayPuroTexto());

        // `puro_texto` no está entre las columnas que la pantalla edita: se muestra y no se toca.
        $conTexto = $this->vista([EcoDoppler::COLUMNA_PURO_TEXTO => "INFORME DEL ESCRITORIO\nSegunda línea."]);
        $this->assertTrue($conTexto->hayPuroTexto());
        $this->assertSame("INFORME DEL ESCRITORIO\nSegunda línea.", $conTexto->puroTexto());
        $this->assertNotContains(EcoDoppler::COLUMNA_PURO_TEXTO, EcoDoppler::columnas());

        // Un estudio sin ningún campo pero con el informe en texto libre del escritorio **sí** es un documento:
        // es lo que se imprime, y la pantalla tiene que ofrecer su hoja (como el eco pélvico).
        $this->assertTrue($conTexto->esUnDocumento());
        $this->assertSame(0, $conTexto->cargados());
    }
}
