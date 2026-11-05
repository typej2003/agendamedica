<?php

namespace Tests\Feature;

use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\ModoDeLaHoja;
use App\Models\Medico;
use App\Models\RecipeFormato;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La hoja de los imprimibles, elegida por el médico** (2026-11-04): vertical, media vertical, horizontal o estilo récipe.
 *
 * Lo que fijan estos casos:
 *
 *  - La pantalla «Formato de impresión» ofrece las cuatro hojas y **guarda** la elección en `recipe_formatos`, bajo el
 *    registro del médico; sin elección, los documentos siguen saliendo como hasta hoy.
 *  - La elección **se aplica** a los documentos de texto: la constancia (y las demás solicitudes) y su formato en blanco.
 *  - **Los récipes no cambian**: siempre media hoja horizontal, aunque la preferencia sea vertical.
 *  - El selector de la hoja de la vista previa (`?hoja=`) **no guarda**: es sólo una vista previa.
 *  - `horizontal` deja la mitad derecha en blanco; `recipe` mete dos documentos en la misma hoja acostada.
 *
 * **La feature está oculta por defecto** (2026-11-05) hasta que la elección sea por reporte: la clase enciende la bandera
 * en `setUp` para probar el código que queda listo, y el último caso la apaga y fija que no se ofrece ni se aplica.
 */
class HojaDeLosImprimiblesWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
        // La hoja única está apagada en producción (`config/clinica.php`); estos casos prueban la feature que queda lista.
        config(['clinica.hoja_de_los_imprimibles' => true]);
        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();
        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);

        return [$user, $medico, $gineco];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hoja(Medico $medico, string $hoja): void
    {
        RecipeFormato::updateOrCreate(
            ['reg_medico' => $medico->reg_medico],
            ['formato_imprimibles' => $hoja],
        );
    }

    private function constancia(Medico $medico): void
    {
        DB::table('constancia_obs')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1,
            'observacion01' => 'Acudió a control.', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reposo(Medico $medico): void
    {
        DB::table('reposo_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1,
            'codereposo' => 'M', 'fdesde' => '2026-10-10', 'numdias' => 8,
            'obser_reposo' => 'Reposo por infección.', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ============================================================================== La pantalla

    public function test_la_pantalla_ofrece_las_cuatro_hojas_y_guarda_la_eleccion(): void
    {
        [$user, $medico, $gineco] = $this->escenario();

        $pantalla = $this->como($user, $medico, $gineco)->get(route('clinica.formato'));

        $pantalla->assertOk()
            ->assertSee('Hoja de los imprimibles')
            ->assertSee('Hoja vertical')
            ->assertSee('Media hoja vertical')
            ->assertSee('Horizontal')
            ->assertSee('Estilo récipe')
            ->assertSee('Los récipes no cambian')
            // Las cuatro opciones, sin ninguna marcada todavía.
            ->assertSee('name="formato_imprimibles"', false);

        $this->assertSame(4, substr_count($pantalla->getContent(), 'name="formato_imprimibles"'));

        // Guarda la elección bajo el registro del médico.
        $this->como($user, $medico, $gineco)
            ->post(route('clinica.formato.hoja'), ['formato_imprimibles' => ModoDeLaHoja::HOJA_HORIZONTAL])
            ->assertRedirect(route('clinica.formato') . '#formato-hoja')
            ->assertSessionHas('estado');

        $this->assertSame(
            ModoDeLaHoja::HOJA_HORIZONTAL,
            DB::table('recipe_formatos')->where('reg_medico', $medico->reg_medico)->value('formato_imprimibles'),
        );

        // Y la deja marcada al volver.
        $this->como($user, $medico, $gineco)->get(route('clinica.formato'))
            ->assertOk()
            ->assertSee('id="hoja-horizontal"', false)
            ->assertSee('value="horizontal"', false);

        // «Volver a como está hoy» borra la preferencia: no queda una hoja forzada.
        $this->como($user, $medico, $gineco)
            ->post(route('clinica.formato.hoja'), ['formato_imprimibles' => ''])
            ->assertRedirect(route('clinica.formato') . '#formato-hoja');

        $this->assertNull(
            DB::table('recipe_formatos')->where('reg_medico', $medico->reg_medico)->value('formato_imprimibles'),
        );
    }

    public function test_el_formato_de_un_centro_no_ofrece_la_hoja(): void
    {
        [$user, $medico, $gineco] = $this->escenario();

        // Un centro se edita con `?reg_medico=<centro>` y por un Root. La pantalla no le ofrece la hoja: no tiene firma.
        $centro = \App\Models\Medico::create([
            'name' => 'Centro', 'lastname' => 'Médico', 'prefix' => 'Dr',
            'email' => 'centro-' . substr(uniqid(), -6) . '@example.com',
            'password' => bcrypt('secreto123'), 'reg_medico' => 'centro-formato-1', 'es_centro' => true,
        ]);

        $user->assignRole('Root');

        $this->como($user, $medico, $gineco)
            ->get(route('clinica.formato', ['reg_medico' => $centro->reg_medico]))
            ->assertOk()
            ->assertDontSee('Hoja de los imprimibles');
    }

    // ======================================================================== La hoja de la constancia

    public function test_la_constancia_sale_en_la_hoja_elegida(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);

        $url = route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::CONSTANCIA]);

        // Horizontal: una hoja acostada con la mitad izquierda y la derecha en blanco.
        $this->hoja($medico, ModoDeLaHoja::HOJA_HORIZONTAL);
        $html = $this->como($user, $medico, $gineco)->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'recipe-mitad-documento'), 'el documento va en una mitad');
        $this->assertSame(1, substr_count($html, 'recipe-mitad-vacia'), 'la otra mitad queda en blanco');
        $this->assertSame(0, substr_count($html, 'recipe-hoja-completa'), 'no es una página vertical');

        // Vertical completa.
        $this->hoja($medico, ModoDeLaHoja::HOJA_VERTICAL);
        $html = $this->como($user, $medico, $gineco)->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'recipe-hoja-completa'), 'una página vertical por documento');
        $this->assertSame(0, substr_count($html, 'recipe-mitad-vacia'));

        // Media vertical: la mitad de arriba de un A4 vertical.
        $this->hoja($medico, ModoDeLaHoja::HOJA_MEDIA_VERTICAL);
        $html = $this->como($user, $medico, $gineco)->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'recipe-hoja-mitad-superior'));
        $this->assertSame(0, substr_count($html, 'recipe-mitad-vacia'));

        // Estilo récipe: la misma media hoja, pero sin dejar la mitad vacía como documento suelto.
        $this->hoja($medico, ModoDeLaHoja::HOJA_RECIPE);
        $html = $this->como($user, $medico, $gineco)->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'recipe-mitad-documento'));
        $this->assertSame(1, substr_count($html, 'recipe-mitad-vacia'), 'un solo documento deja la otra mitad en blanco');
    }

    public function test_estilo_recipe_mete_dos_documentos_en_la_misma_hoja(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);
        $this->reposo($medico);
        $this->hoja($medico, ModoDeLaHoja::HOJA_RECIPE);

        $html = $this->como($user, $medico, $gineco)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'todos']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'class="recipe-hoja '), 'los dos documentos comparten una hoja');
        $this->assertSame(2, substr_count($html, 'recipe-mitad-documento'));
        $this->assertSame(0, substr_count($html, 'recipe-mitad-vacia'));
        $this->assertSame(0, substr_count($html, 'recipe-hoja-completa'));
    }

    public function test_el_formato_en_blanco_sale_en_la_misma_hoja_que_el_documento(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        $this->hoja($medico, ModoDeLaHoja::HOJA_HORIZONTAL);

        $html = $this->como($user, $medico, $gineco)
            ->get(route('clinica.formato.en-blanco', ['formato' => DocumentoImpreso::CONSTANCIA]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'recipe-mitad-documento'));
        $this->assertSame(1, substr_count($html, 'recipe-mitad-vacia'));
    }

    // ============================================================================== Los récipes

    public function test_el_recipe_sigue_en_media_hoja_horizontal_aunque_la_hoja_elegida_sea_vertical(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-06');
        $this->hoja($medico, ModoDeLaHoja::HOJA_VERTICAL);

        $html = $this->como($user, $medico, $gineco)
            ->get($this->urlRecipe($consulta))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'class="recipe-hoja '));
        $this->assertSame(0, substr_count($html, 'recipe-hoja-completa'));
        $this->assertSame(0, substr_count($html, 'recipe-hoja-mitad-superior'));
    }

    // ================================================================= La vista previa no guarda

    public function test_el_selector_de_la_vista_previa_cambia_la_hoja_sin_guardarla(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);
        $this->hoja($medico, ModoDeLaHoja::HOJA_VERTICAL);

        $respuesta = $this->como($user, $medico, $gineco)->get(
            route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::CONSTANCIA, 'hoja' => 'horizontal']),
        );

        $respuesta->assertOk()
            ->assertSee('Hoja', false)
            ->assertSee('Media vertical')
            ->assertSee('Estilo récipe');

        $html = $respuesta->getContent();
        $this->assertSame(1, substr_count($html, 'recipe-mitad-vacia'), 'la vista previa usa la hoja pedida');

        $this->assertSame(
            ModoDeLaHoja::HOJA_VERTICAL,
            DB::table('recipe_formatos')->where('reg_medico', $medico->reg_medico)->value('formato_imprimibles'),
            'la vista previa no guarda la elección',
        );
    }

    // ===================================================================== La feature oculta

    public function test_la_hoja_esta_oculta_y_todo_sale_como_hasta_hoy(): void
    {
        config(['clinica.hoja_de_los_imprimibles' => false]);

        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);

        // Aunque haya una preferencia guardada (la que dejó una prueba en QA), no se aplica.
        $this->hoja($medico, ModoDeLaHoja::HOJA_HORIZONTAL);

        $this->como($user, $medico, $gineco)->get(route('clinica.formato'))
            ->assertOk()
            ->assertDontSee('Hoja de los imprimibles')
            ->assertDontSee('name="formato_imprimibles"', false);

        // La ruta de guardado tampoco existe: una pestaña vieja no puede guardar una hoja que ya no se lee.
        $this->como($user, $medico, $gineco)
            ->post(route('clinica.formato.hoja'), ['formato_imprimibles' => ModoDeLaHoja::HOJA_HORIZONTAL])
            ->assertNotFound();

        $html = $this->como($user, $medico, $gineco)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::CONSTANCIA]))
            ->assertOk()
            ->assertDontSee('barra-impresion-modos', false)
            ->getContent();

        // La constancia vuelve a su hoja de siempre: A4 vertical completo.
        $this->assertSame(1, substr_count($html, 'recipe-hoja-completa'));
        $this->assertSame(0, substr_count($html, 'recipe-mitad-vacia'));

        // `?hoja=` tampoco cambia la hoja: la vista previa de las cuatro hojas está apagada.
        $html = $this->como($user, $medico, $gineco)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::CONSTANCIA, 'hoja' => 'horizontal']))
            ->assertOk()
            ->getContent();
        $this->assertSame(1, substr_count($html, 'recipe-hoja-completa'));
        $this->assertSame(0, substr_count($html, 'recipe-mitad-vacia'));

        // El formato en blanco también sale como el documento real.
        $html = $this->como($user, $medico, $gineco)
            ->get(route('clinica.formato.en-blanco', ['formato' => DocumentoImpreso::CONSTANCIA]))
            ->assertOk()
            ->getContent();
        $this->assertSame(1, substr_count($html, 'recipe-hoja-completa'));
        $this->assertSame(0, substr_count($html, 'recipe-mitad-vacia'));
    }
}
