<?php

namespace Tests\Unit\Clinica;

use App\Actions\Configuracion\ReglasDelFormato;
use App\Clinica\Impresion\FormatoGuardado;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Clinica\Impresion\RecipeDeEjemplo;
use App\Clinica\Impresion\Vistas\FormatoDeImpresionVista;
use App\Clinica\Impresion\Vistas\HojaDePruebaVista;
use App\Models\Evolucion;
use App\Models\RecipeFormato;
use Tests\TestCase;

/**
 * El ViewModel de la pantalla «Formato de impresión» (WEB-8.2), la hoja de prueba y el récipe de ejemplo, **sin base de datos** (arranca la app porque el ViewModel lee `old()` y la fecha del consultorio).
 */
class FormatoDeImpresionVistaTest extends TestCase
{
    private const URLS = [
        'vistaPrevia' => '/p?incrustada=1', 'vistaPreviaCompleta' => '/p', 'membrete' => '/m', 'logo' => '/l',
        'estilo' => '/e', 'firma' => '/f', 'restablecer' => '/r',
    ];

    private function membrete(array $elementos = [], string $color = 'negro', string $posicion = 'centro', ?string $logo = null): Membrete
    {
        return new Membrete(
            'Dra. Ana Prueba', 'Ginecología', 'J-1', $logo, ['Pie'],
            array_replace_recursive(RecipeFormato::elementosPorDefecto(), $elementos), $color, null, null, $posicion,
        );
    }

    private function vista(?Membrete $membrete = null, ?Evolucion $evolucion = null, string $registro = 'R1', ?string $contexto = 'R1'): FormatoDeImpresionVista
    {
        return new FormatoDeImpresionVista(
            new FormatoGuardado($registro, $evolucion, null, $membrete ?? $this->membrete()),
            'Dra. Ana Prueba', $contexto, self::URLS,
        );
    }

    public function test_sin_nada_guardado_todo_es_del_legado_y_los_campos_estan_vacios(): void
    {
        $vista = $this->vista();

        $this->assertTrue($vista->logoEsDelLegado());
        $this->assertTrue($vista->textosSonDelLegado());
        $this->assertTrue($vista->selloEsDelLegado());
        $this->assertSame('Valores del legado', $vista->estadoDelLogo()['texto']);
        $this->assertSame(['especialidad', 'rif', 'linea_1', 'linea_2', 'linea_3'], array_column($vista->camposDelMembrete(), 'campo'));
        $this->assertSame([200, 40, 90, 90, 90], array_column($vista->camposDelMembrete(), 'maximo'));
        $this->assertSame([''], array_unique(array_column($vista->camposDelMembrete(), 'valor')));
        $this->assertFalse($vista->tieneLogo());
        $this->assertCount(2, $vista->firmaYSello());
        $this->assertSame('Subir la firma', $vista->firmaYSello()[0]['etiqueta']);
    }

    public function test_lo_personalizado_se_marca_y_el_restablecer_se_ofrece(): void
    {
        $vista = $this->vista($this->membrete(['medico' => ['fuente' => 'tinos'], 'logo' => ['tamano' => 'grande']], 'rojo', 'derecha', '/storage/logos/a.png'));

        $this->assertFalse($vista->textosSonDelLegado());
        $this->assertFalse($vista->logoEsDelLegado());
        $this->assertFalse($vista->selloEsDelLegado());
        $this->assertSame('Personalizado', $vista->estadoDeLosTextos()['texto']);
        $this->assertTrue($vista->tieneLogo());
        $this->assertSame('derecha', $vista->posicionDelSello());
        $this->assertSame('rojo', collect($vista->colores())->firstWhere('marcado', true)['valor']);
        $this->assertSame('tinos', $vista->bloquesDeTexto()[0]['fuente']);
        $this->assertSame('grande', collect($vista->tamanosDelLogo())->firstWhere('marcada', true)['valor']);
    }

    public function test_los_bloques_cubren_los_elementos_de_texto_y_las_cinco_fuentes_dicen_su_equivalente(): void
    {
        $vista = $this->vista();

        $this->assertSame(RecipeFormato::ELEMENTOS_TEXTO, array_column($vista->bloquesDeTexto(), 'elemento'));
        $this->assertSame(RecipeFormato::FUENTES, array_keys($vista->fuentes()));
        $this->assertStringContainsString('Arial', $vista->fuentes()['arimo']);
        $this->assertCount(9, $vista->colores());
        $this->assertSame(RecipeFormato::COLORES_LINEA, array_column($vista->colores(), 'valor'));
    }

    public function test_el_aviso_de_registro_solo_sale_si_el_consultorio_es_de_otro_registro(): void
    {
        $this->assertNull($this->vista(null, null, 'R1', 'R1')->avisoDeRegistro());
        $this->assertNull($this->vista(null, null, 'R1', null)->avisoDeRegistro());

        $aviso = $this->vista(null, null, 'R1', 'R2')->avisoDeRegistro();
        $this->assertStringContainsString('registro principal (R1)', $aviso);
        $this->assertStringContainsString('R2', $aviso);
    }

    public function test_sin_registro_principal_se_dice(): void
    {
        $this->assertTrue($this->vista(null, null, '')->sinRegistro());
        $this->assertNull($this->vista(null, null, '', 'R2')->avisoDeRegistro());
    }

    public function test_los_textos_del_membrete_salen_de_evolucion_y_los_vacios_son_vacios(): void
    {
        $evolucion = new Evolucion(['especialidad' => 'Pediatría', 'rif' => '   ']);
        $campos = collect($this->vista(null, $evolucion)->camposDelMembrete())->pluck('valor', 'campo');

        $this->assertSame('Pediatría', $campos['especialidad']);
        $this->assertSame('', $campos['rif']);
    }

    public function test_la_hoja_de_prueba_usa_el_recipe_de_ejemplo_y_la_misma_hoja_que_el_recipe(): void
    {
        $vista = new HojaDePruebaVista($this->membrete(), OpcionesDeRecipe::porDefecto(), true, '/volver');
        $hojas = $vista->hojas();

        $this->assertTrue($vista->incrustada());
        $this->assertCount(1, $hojas);
        $this->assertCount(2, $hojas[0]['mitades'], 'Rp. + Indicaciones por defecto.');
        $this->assertSame('Paciente De Prueba', $hojas[0]['mitades'][0]['paciente']);
        $this->assertStringContainsString('Próxima cita:', $hojas[0]['mitades'][0]['proximaCita']);
        $this->assertStringStartsWith('Datos de ejemplo', $vista->resumen());
    }

    public function test_el_recipe_de_ejemplo_tiene_tres_medicamentos_y_una_cita_futura(): void
    {
        $documento = RecipeDeEjemplo::documento();

        $this->assertCount(3, $documento->items);
        $this->assertFalse($documento->esDeLaPareja());
        $this->assertGreaterThan($documento->fecha, RecipeDeEjemplo::proximaCita()['fecha']);
    }

    public function test_las_reglas_del_formato_son_las_del_api(): void
    {
        $reglas = ReglasDelFormato::reglas();

        $this->assertSame('sometimes|image|max:2048', $reglas['firma']);
        $this->assertArrayHasKey('elementos.logo.tamano', $reglas);
        $this->assertArrayHasKey('elementos.pie.fuente', $reglas);
        $this->assertArrayNotHasKey('elementos.logo.fuente', $reglas);
        $this->assertSame(['medico' => ['negrita' => true]], ReglasDelFormato::soloOpcionesConocidas(['medico' => ['negrita' => '1', 'sombra' => 1], 'otro' => ['x' => 1]]));
    }
}
