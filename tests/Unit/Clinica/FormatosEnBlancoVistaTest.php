<?php

namespace Tests\Unit\Clinica;

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

    public function test_el_catalogo_son_nueve_formatos_con_el_recipe_primero_y_sin_las_ecos(): void
    {
        $this->assertSame(
            ['recipe', 'informe', 'constancia', 'reposo', 'referencia', 'hospitalizacion', 'dieta', 'laboratorio', 'estudios'],
            FormatosEnBlanco::claves(),
        );
        $this->assertSame(FormatosEnBlanco::claves(), array_column(FormatosEnBlanco::catalogo(), 'clave'));
        $this->assertFalse(FormatosEnBlanco::existe('ecografia'));
    }

    public function test_los_titulos_salen_de_los_de_la_hoja_real(): void
    {
        foreach (DocumentoImpreso::TITULOS as $clave => [$titulo, $nombre]) {
            $this->assertSame($titulo, FormatosEnBlanco::titulo($clave));
            $this->assertSame($nombre, FormatosEnBlanco::nombre($clave));
        }
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
