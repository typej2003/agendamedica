<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Models\EcoPelvico;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa del dato del eco pélvico** (WEB-6.1), sin base de datos: las columnas que la web edita, las que
 * no toca, y los códigos del legado de cada desplegable.
 *
 * Estos casos son el contrato del mapa con el resto del sistema: la pantalla, la Action y el informe leen
 * de acá, así que una columna nueva que se olvide de entrar (o una que entre dos veces) se ve en el build,
 * no en el navegador.
 */
class EcoGinecologicoTest extends TestCase
{
    public function test_las_columnas_editables_no_se_repiten_y_son_las_del_formulario(): void
    {
        $columnas = EcoGinecologico::columnas();

        // 35 columnas del DataWindow `d_eco_pelvico` con control en la pantalla (los nueve bloques).
        $this->assertCount(35, $columnas);
        $this->assertSame(count($columnas), count(array_unique(array_keys($columnas))));
        $this->assertSame(count($columnas), count(EcoGinecologico::campos()));

        // Todas salen de la misma tabla y tienen su rótulo de pantalla (uno puede coincidir con el nombre
        // de la columna —`PARA` es el rótulo del escritorio—, pero ninguno queda sin rótulo).
        foreach ($columnas as $columna => $tabla) {
            $this->assertSame(EcoGinecologico::TABLA, $tabla);
            $this->assertNotSame('', EcoGinecologico::etiquetaDe($columna));
        }

        // Una columna que no está en el mapa se devuelve tal cual: el rótulo nunca se inventa.
        $this->assertSame('inventada', EcoGinecologico::etiquetaDe('inventada'));
    }

    public function test_las_columnas_que_la_pantalla_no_toca_no_entran_en_el_formulario(): void
    {
        $queNoSeTocan = EcoGinecologico::columnasQueNoSeTocan();

        $this->assertSame(
            ['puro_texto', 'centrar', 'cuello', 'anterocuello', 'formautero'],
            array_keys($queNoSeTocan)
        );

        foreach (array_keys($queNoSeTocan) as $columna) {
            $this->assertArrayNotHasKey($columna, EcoGinecologico::columnas(), "La web no debe editar {$columna}.");
        }
    }

    /**
     * Las columnas editables tienen que estar en el `$fillable` del modelo: la Action escribe por Eloquent y
     * una columna que falte ahí se perdería en silencio.
     */
    public function test_todas_las_columnas_editables_se_pueden_escribir_con_el_modelo(): void
    {
        $modelo = new EcoPelvico();
        $fillable = $modelo->getFillable();

        foreach (array_keys(EcoGinecologico::columnas()) as $columna) {
            $this->assertContains($columna, $fillable, "El modelo no deja escribir {$columna}.");
        }
    }

    /** Los códigos del legado, tal como los declara el `values` de cada columna del DataWindow. */
    public function test_los_desplegables_conservan_los_codigos_del_legado(): void
    {
        $this->assertSame([
            ['texto' => 'AVF', 'valor' => '1'],
            ['texto' => 'RVF', 'valor' => '2'],
            ['texto' => 'Indiferente', 'valor' => '3'],
        ], EcoGinecologico::opcionesDe('posicionavf'));

        // La gesta va de I a XI y termina en el `0` del escritorio.
        $gesta = EcoGinecologico::opcionesDe('gesta');
        $this->assertCount(12, $gesta);
        $this->assertSame('I', $gesta[0]['texto']);
        $this->assertSame('0', $gesta[11]['valor']);

        // Las columnas cuyo nombre no coincide con el rótulo ni con sus valores.
        $this->assertSame([['texto' => 'Globosa', 'valor' => '1'], ['texto' => 'Piriforme', 'valor' => '2']], EcoGinecologico::opcionesDe('fondosacolibre'));
        $this->assertSame([['texto' => 'Llena', 'valor' => 'L'], ['texto' => 'Vacía', 'valor' => 'V']], EcoGinecologico::opcionesDe('linea_cavidad_u'));
        $this->assertSame([['texto' => 'Llena', 'valor' => 'H'], ['texto' => 'Vacia', 'valor' => 'V']], EcoGinecologico::opcionesDe('vagina'));

        // Un campo que no es un desplegable no tiene opciones.
        $this->assertSame([], EcoGinecologico::opcionesDe('cuerpo'));
    }

    public function test_el_texto_de_un_valor_es_su_rotulo_y_un_codigo_desconocido_se_muestra_tal_cual(): void
    {
        $this->assertSame('AVF', EcoGinecologico::textoDe('posicionavf', '1'));
        $this->assertSame('Z', EcoGinecologico::textoDe('posicionavf', 'Z'));
        $this->assertNull(EcoGinecologico::textoDe('posicionavf', null));
        $this->assertNull(EcoGinecologico::textoDe('posicionavf', ''));
        $this->assertNull(EcoGinecologico::textoDe('posicionavf', 'none'));
    }

    /** Las unidades del informe salen del DataWindow de impresión (`d_eco_pelvico_print.srd`). */
    public function test_las_medidas_llevan_la_unidad_del_informe(): void
    {
        $unidades = [];
        foreach (EcoGinecologico::campos() as $campo) {
            $unidades[$campo['columna']] = $campo['unidad'];
        }

        $this->assertSame('MHz', $unidades['trans_convex']);
        $this->assertSame('mm', $unidades['cuerpo']);
        $this->assertSame('mm', $unidades['ovarioderlong']);
        $this->assertSame('cc', $unidades['volumen_der']);
        $this->assertNull($unidades['idxecografico']);
    }

    /** El "sin dato" del legado: `NULL`, la cadena vacía y el literal `none` son lo mismo. */
    public function test_el_objeto_de_valores_normaliza_los_vacios_y_no_pierde_el_cero(): void
    {
        $estudio = new EstudioEcografico(['cuerpo' => '62.5', 'gesta' => 0, 'observacion' => 'none', 'fur' => '']);

        $this->assertSame('62.5', $estudio->valor('cuerpo'));
        // El `0` de la gesta es un valor del legado, no un vacío.
        $this->assertSame('0', $estudio->valor('gesta'));
        $this->assertNull($estudio->valor('observacion'));
        $this->assertNull($estudio->valor('fur'));
        $this->assertNull($estudio->valor('sugerencias'));
        $this->assertSame(62.5, $estudio->numero('cuerpo'));
        $this->assertNull($estudio->numero('sugerencias'));
        $this->assertTrue($estudio->tieneAlgoDe(['cuerpo', 'gesta']));
        $this->assertFalse($estudio->tieneAlgoDe(['observacion', 'fur']));
    }

    /**
     * El registro de tipos: los que ya existen (el ginecológico de WEB-6.1, los dos obstétricos de WEB-6.2, el
     * Doppler perinatal y el ecocardiograma fetal de WEB-6.3 y el mamario de WEB-6.4), y un valor raro cae al
     * que existe.
     *
     * ⚠️ Este test fija la **lista**, así que encender un tipo lo rompe: es el mismo caso de la trampa 96 (hay
     * aserciones de listas que no se ven en una búsqueda del contador del panel).
     */
    public function test_el_registro_de_tipos_tiene_los_que_existen_y_un_valor_raro_cae_al_primero(): void
    {
        $this->assertSame(['ginecologico', 'obstetrico', 'tercero', 'doppler', 'mamario', 'fetal'], TiposDeEstudio::claves());
        $this->assertSame('ginecologico', TiposDeEstudio::normalizar('inventado'));
        $this->assertSame('ginecologico', TiposDeEstudio::normalizar(null));
        $this->assertTrue(TiposDeEstudio::existe('ginecologico'));
        $this->assertTrue(TiposDeEstudio::existe('obstetrico'));
        // La clave del Doppler va sin guion: la ruta del guardado acota `[a-z]+` (trampa 111).
        $this->assertTrue(TiposDeEstudio::existe('doppler'));
        // La del mamario, también: `mamario` y no `eco-mamario` (trampa 111).
        $this->assertTrue(TiposDeEstudio::existe('mamario'));
        // La del ecocardiograma fetal, también: `fetal` y no `eco-fetal`.
        $this->assertTrue(TiposDeEstudio::existe('fetal'));
        $this->assertSame('Eco ginecológico', TiposDeEstudio::texto('ginecologico'));
        $this->assertSame('Eco obstétrico (1.er trimestre)', TiposDeEstudio::texto('obstetrico'));
        $this->assertSame('Eco Doppler perinatal', TiposDeEstudio::texto('doppler'));
        $this->assertSame('Eco mamario', TiposDeEstudio::texto('mamario'));
        $this->assertSame('Ecocardiograma fetal', TiposDeEstudio::texto('fetal'));
    }
}
