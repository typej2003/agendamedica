<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EvaluacionFisica;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\Vistas\EvaluacionFisicaVista;
use PHPUnit\Framework\TestCase;

/**
 * **La evaluación física de una consulta** (WEB-4.3), sin base de datos: el mapa de campos, los valores
 * sucios del legado y el IMC derivado.
 *
 * Todo lo que el ViewModel necesita llega por el constructor (mismo criterio que los otros ViewModels de
 * la consulta), así que acá no hace falta ni Laravel.
 */
class EvaluacionFisicaVistaTest extends TestCase
{
    private function vista(array $consulta = [], array $examen = [], array $old = []): EvaluacionFisicaVista
    {
        return new EvaluacionFisicaVista(
            new EvaluacionFisica($consulta, $examen),
            '/clinica/consulta/42/evaluacion',
            '/clinica/consulta/42?seccion=evaluacion',
            $old,
        );
    }

    private function porColumna(array $filas): array
    {
        $porColumna = [];
        foreach ($filas as $fila) {
            $porColumna[$fila['columna']] = $fila;
        }

        return $porColumna;
    }

    // ----------------------------------------------------------------------- El mapa de los campos

    public function test_las_tres_listas_tienen_las_columnas_de_la_fuente_y_nada_mas(): void
    {
        // Las siete columnas de `consultas` (peso, talla, FC, FR y las tres circunferencias) más la
        // tensión, que el escritorio guarda en el examen.
        $this->assertSame([
            'peso', 'talla', 'tension', 'fc', 'pp', 'circabdominal', 'circtoraxica', 'circcefalica',
        ], array_column(ExamenFisicoDeLaConsulta::antropometria(), 'columna'));

        // Los ocho hallazgos de la solapa "Examen Físico" del escritorio.
        $this->assertSame([
            'general', 'cardiopulmonar', 'tiroides', 'mamas', 'mama_derecha',
            'abdomen', 'extremidades', 'neurologico',
        ], array_column(ExamenFisicoDeLaConsulta::general(), 'columna'));

        // Los cuatro del examen ginecológico.
        $this->assertSame([
            'genitales_externos', 'especulos', 'tacto_vaginal', 'tacto_rectal',
        ], array_column(ExamenFisicoDeLaConsulta::ginecologico(), 'columna'));

        // Y el denominador de la pantalla: 8 + 8 + 4, sin la colposcopia ni los resúmenes.
        $this->assertCount(20, EvaluacionFisica::columnas());
        $this->assertArrayNotHasKey('colposcopia', EvaluacionFisica::columnas());
        $this->assertArrayNotHasKey('total_f', EvaluacionFisica::columnas());
        $this->assertArrayNotHasKey('total_g', EvaluacionFisica::columnas());
        $this->assertArrayNotHasKey('mamas_izquierda', EvaluacionFisica::columnas());
    }

    public function test_cada_columna_sabe_de_que_tabla_sale(): void
    {
        $columnas = EvaluacionFisica::columnas();

        // La antropometría y los signos son de `consultas`; el examen y la tensión, de `examen_fisico_nuevo`.
        foreach (['peso', 'talla', 'fc', 'pp', 'circcefalica', 'circtoraxica', 'circabdominal'] as $columna) {
            $this->assertSame('consultas', $columnas[$columna], $columna);
        }

        foreach (['general', 'cardiopulmonar', 'tiroides', 'mamas', 'mama_derecha', 'abdomen',
            'extremidades', 'neurologico', 'genitales_externos', 'especulos', 'tacto_vaginal',
            'tacto_rectal', 'tension'] as $columna) {
            $this->assertSame('examen_fisico_nuevo', $columnas[$columna], $columna);
        }
    }

    /**
     * Los rótulos de las mamas son los del escritorio y están **cambiados** respecto de las columnas: es
     * una trampa del legado que la web conserva a propósito (intercambiarlas duplicaría o perdería datos).
     */
    public function test_los_rotulos_de_las_mamas_son_los_del_escritorio(): void
    {
        $filas = $this->porColumna(ExamenFisicoDeLaConsulta::general());

        $this->assertSame('Mama derecha', $filas['mamas']['etiqueta']);
        $this->assertSame('Mama izquierda', $filas['mama_derecha']['etiqueta']);
    }

    // ------------------------------------------------------------------- Los valores sucios del legado

    public function test_los_valores_en_blanco_y_el_literal_none_son_sin_dato(): void
    {
        $vista = $this->vista(
            ['peso' => 70.0, 'talla' => '', 'fc' => '   '],
            ['general' => 'none', 'abdomen' => 'Blando'],
        );

        $filas = $this->porColumna($vista->antropometria());
        $this->assertSame('70', $filas['peso']['valor']);
        $this->assertSame('', $filas['talla']['valor']);
        $this->assertSame('', $filas['fc']['valor']);

        // `none` es el marcador que el DataWindow del escritorio dejó en `total_general`: no es un hallazgo.
        $this->assertSame('', $this->porColumna($vista->general())['general']['valor']);
        $this->assertSame('Blando', $this->porColumna($vista->general())['abdomen']['valor']);
        $this->assertSame(2, $vista->cargados());
    }

    public function test_un_valor_con_espacios_de_mas_se_recorta(): void
    {
        $vista = $this->vista(['peso' => ' 70 '], ['genitales_externos' => "  Normales  "]);

        $this->assertSame('70', $this->porColumna($vista->antropometria())['peso']['valor']);
        $this->assertSame('Normales', $this->porColumna($vista->ginecologico())['genitales_externos']['valor']);
    }

    public function test_el_estado_dice_sin_cargar_cuando_no_hay_ningun_dato(): void
    {
        $vacia = $this->vista();
        $this->assertSame(0, $vacia->cargados());
        $this->assertSame('Sin cargar', $vacia->estado()['texto']);
        $this->assertSame('neutro', $vacia->estado()['tono']);

        $conDatos = $this->vista(['peso' => 70.0]);
        $this->assertSame(1, $conDatos->cargados());
        $this->assertSame(20, $conDatos->totalCampos());
        $this->assertSame('Cargada', $conDatos->estado()['texto']);
        $this->assertSame('exito', $conDatos->estado()['tono']);
    }

    // ------------------------------------------------------------------------------------ El IMC

    public function test_el_imc_es_peso_sobre_talla_al_cuadrado(): void
    {
        // El mismo cálculo de `gffimc.srf`: 70 / 1,62² = 26,67.
        $vista = $this->vista(['peso' => 70.0, 'talla' => 1.62]);

        $this->assertSame('26,67', $vista->imc());
    }

    public function test_el_imc_sin_peso_o_sin_talla_es_una_raya(): void
    {
        $this->assertSame('—', $this->vista(['peso' => 70.0])->imc());
        $this->assertSame('—', $this->vista(['talla' => 1.62])->imc());
        $this->assertSame('—', $this->vista()->imc());
    }

    public function test_el_imc_con_talla_o_peso_imposibles_no_inventa_un_numero(): void
    {
        // El escritorio devuelve 0 si la talla o el peso son <= 0; la pantalla muestra "—".
        $this->assertSame('—', $this->vista(['peso' => 70.0, 'talla' => 0])->imc());
        $this->assertSame('—', $this->vista(['peso' => 0, 'talla' => 1.62])->imc());
        $this->assertSame('—', $this->vista(['peso' => 'no es un número', 'talla' => 1.62])->imc());
    }

    // ----------------------------------------------------------------- Los resúmenes del escritorio

    public function test_los_resumenes_del_escritorio_se_muestran_tal_cual(): void
    {
        $vista = $this->vista([], [
            'total_f' => 'PESO: 70 kg.  TALLA: 1,62 m.',
            'total_g' => 'GENITALES EXTERNOS: normales.  COLPOSCOPÍA: sin lesiones.',
        ]);

        $this->assertTrue($vista->hayResumenes());
        $this->assertSame('PESO: 70 kg.  TALLA: 1,62 m.', $vista->resumenGeneral());
        // El resumen ginecológico del escritorio trae la colposcopia adentro: se muestra entero y no se
        // recalcula (si se regenerara con los cuatro campos de la web, esa parte se perdería).
        $this->assertStringContainsString('COLPOSCOPÍA', $vista->resumenGinecologico());
    }

    public function test_sin_resumenes_la_tarjeta_no_se_muestra(): void
    {
        $this->assertFalse($this->vista()->hayResumenes());
        $this->assertNull($this->vista()->resumenGeneral());
        $this->assertNull($this->vista()->resumenGinecologico());
    }

    public function test_un_resumen_vacio_o_none_no_cuenta_como_resumen(): void
    {
        $vista = $this->vista([], ['total_f' => '   ', 'total_g' => 'none']);

        $this->assertFalse($vista->hayResumenes());
        $this->assertNull($vista->resumenGeneral());
        $this->assertNull($vista->resumenGinecologico());
    }

    // ------------------------------------------------------------------- El old() de un POST fallido

    public function test_lo_escrito_en_un_post_que_fallo_no_se_borra(): void
    {
        $vista = $this->vista(
            ['peso' => 70.0, 'fc' => 80.0],
            ['abdomen' => 'Lo guardado'],
            ['peso' => '71.5', 'abdomen' => 'Lo que se acaba de escribir'],
        );

        $this->assertSame('71.5', $this->porColumna($vista->antropometria())['peso']['valor']);
        // Lo que no vino en el `old()` sigue mostrando lo guardado.
        $this->assertSame('80', $this->porColumna($vista->antropometria())['fc']['valor']);
        $this->assertSame('Lo que se acaba de escribir', $this->porColumna($vista->general())['abdomen']['valor']);
    }

    public function test_un_campo_vaciado_en_el_formulario_queda_vacio_y_no_vuelve_al_valor_guardado(): void
    {
        // Un `old()` con la clave vacía es "el médico lo borró", no "no vino": mostrar lo guardado otra vez
        // haría imposible vaciar un campo.
        $vista = $this->vista(['peso' => 70.0], [], ['peso' => '']);

        $this->assertSame('', $this->porColumna($vista->antropometria())['peso']['valor']);
    }

    // ------------------------------------------------------------------------------------- Las URLs

    public function test_las_urls_las_resuelve_el_controlador(): void
    {
        $vista = $this->vista();

        $this->assertSame('/clinica/consulta/42/evaluacion', $vista->urlGuardar());
        $this->assertSame('/clinica/consulta/42?seccion=evaluacion', $vista->urlVolver());
    }
}
