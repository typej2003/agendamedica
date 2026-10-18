<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\FormatosEnBlanco;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Clinica\Impresion\Vistas\HojaEnBlancoVista;
use App\Models\RecipeFormato;
use PHPUnit\Framework\TestCase;

/**
 * El catálogo de los formatos en blanco y su ViewModel (WEB-5.5b), **sin base de datos**.
 */
class FormatosEnBlancoVistaTest extends TestCase
{
    private function vista(string $clave, ?string $modoGuardado = null, ?string $modoElegido = null): HojaEnBlancoVista
    {
        return new HojaEnBlancoVista(
            $clave,
            new Membrete('Dra. Ana Prueba', 'Ginecología', 'J-1', null, ['Pie'], RecipeFormato::elementosPorDefecto(), 'negro', null, null, 'centro'),
            OpcionesDeRecipe::de($modoGuardado, null),
            $modoElegido,
            ['volver' => '/formato', 'hoja' => '/formato/en-blanco/' . $clave],
        );
    }

    public function test_el_catalogo_son_trece_formatos_con_el_recipe_primero_y_sin_el_eco_pelvico(): void
    {
        $this->assertSame(
            ['recipe', 'informe', 'constancia', 'reposo', 'referencia', 'hospitalizacion', 'dieta', 'laboratorio', 'estudios',
                'eco-obstetrico', 'eco-obstetrico-segundo', 'eco-obstetrico-tercero', 'eco-doppler'],
            FormatosEnBlanco::claves(),
        );
        $this->assertSame(FormatosEnBlanco::claves(), array_column(FormatosEnBlanco::catalogo(), 'clave'));
        $this->assertFalse(FormatosEnBlanco::existe('ecografia'));
        // El único documento de la hoja real que todavía no tiene formato en blanco es el eco pélvico
        // (WEB-6.1); los dos del obstétrico (WEB-6.2) y el del Doppler (WEB-6.3) sí.
        $this->assertFalse(FormatosEnBlanco::existe(DocumentoImpreso::ECO_PELVICO));
        $this->assertTrue(FormatosEnBlanco::existe(DocumentoImpreso::ECO_OBSTETRICO));
        $this->assertTrue(FormatosEnBlanco::existe(DocumentoImpreso::ECO_OBSTETRICO_TERCERO));
        $this->assertTrue(FormatosEnBlanco::existe(DocumentoImpreso::ECO_DOPPLER));
    }

    public function test_los_titulos_salen_de_los_de_la_hoja_real(): void
    {
        // Los formatos en blanco toman su título y su nombre de la hoja real (`DocumentoImpreso`), que es la
        // única fuente de esos textos —el título con su sufijo del trimestre incluido—. Se saltea lo que no
        // tiene formato en blanco: el **eco pélvico** (WEB-6.1) es un documento de la hoja real que todavía
        // no lo tiene —el escritorio sí, `d_eco_pelvico_print_vacio.srd`—, y el **récipe** tiene su propia
        // familia de títulos.
        foreach (array_keys(DocumentoImpreso::TITULOS) as $clave) {
            if (! FormatosEnBlanco::existe($clave)) {
                continue;
            }

            $this->assertSame(DocumentoImpreso::tituloConSufijo($clave), FormatosEnBlanco::titulo($clave));
            $this->assertSame(DocumentoImpreso::TITULOS[$clave][1], FormatosEnBlanco::nombre($clave));
        }

        // El título de un eco obstétrico dice el trimestre (el escritorio también lo imprime): los dos
        // formatos no pueden salir con el mismo encabezado.
        $this->assertSame('ECOSONOGRAFÍA OBSTÉTRICA · PRIMER TRIMESTRE', FormatosEnBlanco::titulo(DocumentoImpreso::ECO_OBSTETRICO));
        $this->assertSame('ECOSONOGRAFÍA OBSTÉTRICA · SEGUNDO TRIMESTRE', FormatosEnBlanco::titulo(DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO));
        $this->assertSame('ECOSONOGRAFÍA OBSTÉTRICA · TERCER TRIMESTRE', FormatosEnBlanco::titulo(DocumentoImpreso::ECO_OBSTETRICO_TERCERO));
    }

    /**
     * Las secciones en blanco del eco obstétrico son **los bloques de su informe**, sin el de la ficha del
     * embarazo (que sale arriba como datos cortos): es lo que hace que el médico escriba cada hallazgo donde
     * lo lee la hoja. Si un bloque del mapa cambia de rótulo, este test obliga a cambiar el formato en blanco.
     */
    public function test_las_secciones_del_eco_obstetrico_son_los_bloques_de_su_informe(): void
    {
        foreach ([
            DocumentoImpreso::ECO_OBSTETRICO => EcoObstetrico::grupos(),
            DocumentoImpreso::ECO_OBSTETRICO_TERCERO => EcoObstetricoTercero::grupos(),
        ] as $clave => $bloques) {
            $esperadas = [];
            foreach ($bloques as $bloque) {
                if ($bloque['titulo'] === 'El embarazo') {
                    continue;
                }

                $esperadas[] = $bloque['titulo'] . ':';
            }

            $this->assertSame($esperadas, array_column(FormatosEnBlanco::plano($clave)['secciones'], 'etiqueta'), "Las secciones de {$clave}.");
        }
    }

    public function test_el_eco_obstetrico_en_blanco_trae_su_ficha_y_sus_renglones(): void
    {
        $this->assertSame(['Gesta:', 'FUR:', 'EG:'], FormatosEnBlanco::plano(DocumentoImpreso::ECO_OBSTETRICO)['fijos']);
        // El del 2.º/3.er trimestre agrega la FPP, como su DataWindow en blanco.
        $this->assertSame(['Gesta:', 'FUR:', 'EG:', 'FPP:'], FormatosEnBlanco::plano(DocumentoImpreso::ECO_OBSTETRICO_TERCERO)['fijos']);

        $mitad = $this->vista(DocumentoImpreso::ECO_OBSTETRICO)->hojas()[0]['mitades'][0];
        $this->assertSame(['', '', ''], array_column($mitad['fijos'], 'valor'));
        $this->assertCount(5, $mitad['secciones']);
        $this->assertTrue($mitad['enBlanco']);
    }

    /**
     * El formato en blanco del **eco Doppler perinatal** (WEB-6.3) lleva los mismos bloques que su hoja real,
     * vacíos, en su orden: el memo grande (sin rótulo), la grilla de cuatro vasos, los tres desplegables y las
     * dos áreas del final, rotuladas como en la hoja («Sugerencias:» y «Conclusión:»).
     *
     * ⚠️ El diseño del escritorio (`d_eco_doppler_print_vacio`) es **otro** y no se copia: ver el comentario
     * de la entrada en `FormatosEnBlanco`.
     */
    public function test_el_eco_doppler_en_blanco_trae_su_ficha_y_los_bloques_de_su_hoja(): void
    {
        $this->assertSame(['Gesta:', 'FUR:', 'EG:', 'PARA:'], FormatosEnBlanco::plano(DocumentoImpreso::ECO_DOPPLER)['fijos']);
        $this->assertSame(
            [null, 'Eco Doppler Obstétrico en 4 Vasos:', 'Ductus venoso:', 'Señal Venoso Umbilical:',
                'Relación Aorta-Cava:', 'Sugerencias:', 'Conclusión:'],
            array_column(FormatosEnBlanco::plano(DocumentoImpreso::ECO_DOPPLER)['secciones'], 'etiqueta'),
        );
        $this->assertSame('ECO DOPPLER PERINATAL', FormatosEnBlanco::titulo(DocumentoImpreso::ECO_DOPPLER));

        $mitad = $this->vista(DocumentoImpreso::ECO_DOPPLER)->hojas()[0]['mitades'][0];
        $this->assertSame(['', '', '', ''], array_column($mitad['fijos'], 'valor'));
        $this->assertCount(7, $mitad['secciones']);
        $this->assertTrue($mitad['enBlanco']);
    }

    public function test_un_documento_es_una_sola_mitad_en_blanco_con_firma_y_sin_continuacion(): void
    {
        $hojas = $this->vista('reposo')->hojas();

        $this->assertCount(1, $hojas);
        $this->assertSame('documento', $hojas[0]['tipo']);
        $this->assertCount(1, $hojas[0]['mitades']);

        $mitad = $hojas[0]['mitades'][0];
        $this->assertTrue($mitad['enBlanco']);
        $this->assertTrue($mitad['primera']);
        $this->assertTrue($mitad['firma']);
        $this->assertFalse($mitad['sigue']);
        $this->assertSame(['', '', '', ''], array_column($mitad['fijos'], 'valor'));
        $this->assertSame('', $mitad['paciente']);
        $this->assertSame('', $mitad['cedula']);
        $this->assertSame('', $mitad['fecha']);
    }

    public function test_el_recipe_sigue_el_modo_guardado_y_el_elegido_manda_sin_guardarse(): void
    {
        $this->assertCount(2, $this->vista('recipe')->hojas()[0]['mitades']);
        $this->assertCount(1, $this->vista('recipe', RecipeFormato::MODO_SOLO_INDICACIONES)->hojas()[0]['mitades']);
        $this->assertCount(2, $this->vista('recipe', RecipeFormato::MODO_SOLO_INDICACIONES, RecipeFormato::MODO_RP_E_INDICACIONES)->hojas()[0]['mitades']);
        $this->assertCount(1, $this->vista('recipe', null, RecipeFormato::MODO_SOLO_INDICACIONES)->hojas()[0]['mitades']);
        $this->assertCount(2, $this->vista('recipe', null, 'inventado')->hojas()[0]['mitades']);

        $mitades = $this->vista('recipe')->hojas()[0]['mitades'];
        $this->assertSame(['Rp.', 'Indicaciones:'], array_column($mitades, 'titulo'));
        $this->assertNull($mitades[0]['qr'], 'Una hoja en blanco no lleva QR.');
    }

    public function test_los_modos_son_enlaces_get_solo_para_el_recipe(): void
    {
        $modos = $this->vista('recipe', RecipeFormato::MODO_SOLO_INDICACIONES)->modos();

        $this->assertSame([false, true], array_column($modos, 'activa'));
        $this->assertSame('/formato/en-blanco/recipe?modo=rp_indicaciones', $modos[0]['url']);
        $this->assertSame([], $this->vista('informe')->modos());
        $this->assertStringContainsString('Sin datos de paciente', $this->vista('informe')->resumen());
    }
}
