<?php

namespace Tests\Feature;

use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\HojaDelImprimible;
use App\Clinica\Impresion\ModoDeLaHoja;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\RecipeFormato;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La hoja de cada imprimible, elegida desde la vista previa** (2026-11-05): el botón de configuración que está al lado de
 * «Imprimir» ofrece hoja completa, media hoja vertical y media hoja horizontal, con la hoja actual marcada, y la recuerda
 * **por documento** en `recipe_formatos.hojas_de_los_imprimibles`.
 *
 * Lo que fijan estos casos:
 *
 *  - El botón y su diálogo aparecen cuando se imprime **un** documento configurable, con su hoja actual marcada.
 *  - Guardar cambia **sólo ese imprimible**: la constancia en media vertical no toca el reposo.
 *  - La media vertical usa la página de la mitad (`recipe-hoja-media-vertical`, A5 acostada) y no deja la mitad vacía.
 *  - La media horizontal **nunca** usa la página completa; si el texto sigue, sigue en otra mitad izquierda.
 *  - La hoja en blanco sale igual que la hoja real y el récipe no cambia (siempre media hoja horizontal).
 *  - La secretaría cambia la hoja desde la cita y queda en la fila del doctor que firma.
 */
class HojaDeLosImprimiblesWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function informe(Medico $medico, string $texto = "Paciente en control.\nSin hallazgos."): void
    {
        DB::table('informe')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1,
            'para' => null, 'descripcion' => $texto, 'fe_cha' => '2026-10-06',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function url(Consulta $consulta, string $documento): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    private function guardadas(Medico $medico): array
    {
        return RecipeFormato::where('reg_medico', $medico->reg_medico)->first()?->hojas_de_los_imprimibles ?? [];
    }

    // ======================================================================== El botón y su diálogo

    public function test_la_vista_previa_ofrece_el_boton_con_la_hoja_actual_marcada(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->url($consulta, DocumentoImpreso::CONSTANCIA));

        $respuesta->assertOk()
            ->assertSee('data-abrir-hoja', false)
            ->assertSee('id="dialogo-hoja"', false)
            ->assertSee('Hoja completa')
            ->assertSee('Media hoja vertical')
            ->assertSee('Media hoja horizontal')
            ->assertSee('Formato de la hoja: Hoja completa');

        $html = $respuesta->getContent();
        $this->assertSame(3, substr_count($html, 'name="hoja"'), 'la constancia ofrece las tres hojas');
        $this->assertSame(1, substr_count($html, 'aria-current="true"'), 'sólo la hoja actual queda marcada');
        // La constancia es una solicitud: por defecto, el A4 vertical completo.
        $this->assertStringContainsString('recipe-hoja recipe-hoja-completa', $html);
    }

    public function test_cambiar_la_hoja_guarda_solo_ese_imprimible(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);
        $this->reposo($medico);

        $this->como($user, $medico, $gineco)
            ->post(route('clinica.consulta.documentos.hoja', $consulta->id), [
                'documento' => DocumentoImpreso::CONSTANCIA,
                'hoja' => HojaDelImprimible::MEDIA_VERTICAL,
            ])
            ->assertRedirect($this->url($consulta, DocumentoImpreso::CONSTANCIA))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [DocumentoImpreso::CONSTANCIA => HojaDelImprimible::MEDIA_VERTICAL],
            $this->guardadas($medico),
            'sólo se guarda el documento que se estaba imprimiendo',
        );

        // La constancia cambia de hoja y el diálogo marca la nueva.
        $html = $this->como($user, $medico, $gineco)->get($this->url($consulta, DocumentoImpreso::CONSTANCIA))->getContent();
        $this->assertStringContainsString('recipe-hoja recipe-hoja-media-vertical', $html);
        $this->assertStringNotContainsString('recipe-hoja-completa', $html);
        $this->assertStringContainsString('Formato de la hoja: Media hoja vertical', $html);

        // El reposo, que no se tocó, sigue en la hoja completa de siempre.
        $reposo = $this->como($user, $medico, $gineco)->get($this->url($consulta, DocumentoImpreso::REPOSO))->getContent();
        $this->assertStringContainsString('recipe-hoja recipe-hoja-completa', $reposo);
        $this->assertStringContainsString('Formato de la hoja: Hoja completa', $reposo);
    }

    public function test_con_varios_documentos_no_hay_una_sola_hoja_que_cambiar(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);
        $this->reposo($medico);

        // La elección es por imprimible: en un lote de varios no hay una hoja sola que ofrecer.
        $html = $this->como($user, $medico, $gineco)
            ->get($this->url($consulta, 'todos'))
            ->assertOk()
            ->assertDontSee('data-abrir-hoja', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'recipe-hoja-completa'), 'cada documento sale con su hoja');
    }

    public function test_un_post_inventado_no_guarda_nada(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);

        // Un documento que no admite la elección (el récipe) no se configura.
        $this->como($user, $medico, $gineco)
            ->post(route('clinica.consulta.documentos.hoja', $consulta->id), ['documento' => 'recipe', 'hoja' => HojaDelImprimible::COMPLETA])
            ->assertNotFound();

        // Una hoja que no es de las tres: no se guarda.
        $this->como($user, $medico, $gineco)
            ->post(route('clinica.consulta.documentos.hoja', $consulta->id), ['documento' => DocumentoImpreso::CONSTANCIA, 'hoja' => 'carta'])
            ->assertSessionHasErrors('hoja');

        $this->assertSame([], $this->guardadas($medico));
    }

    // ===================================================================== Las tres hojas

    public function test_la_media_vertical_usa_la_pagina_de_la_mitad_y_no_deja_la_mitad_vacia(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);

        RecipeFormato::create([
            'reg_medico' => $medico->reg_medico,
            'hojas_de_los_imprimibles' => [DocumentoImpreso::CONSTANCIA => HojaDelImprimible::MEDIA_VERTICAL],
        ]);

        $html = $this->como($user, $medico, $gineco)->get($this->url($consulta, DocumentoImpreso::CONSTANCIA))->assertOk()->getContent();

        $this->assertStringContainsString('recipe-hoja recipe-hoja-media-vertical', $html);
        $this->assertStringNotContainsString('recipe-mitad-vacia', $html, 'la página mide la mitad: no hay nada vacío');
        $this->assertStringNotContainsString('recipe-hoja-completa', $html);

        // La página del PDF es la mitad de un A4 vertical (A5 acostada), no un A4 con la mitad de abajo en blanco.
        $css = file_get_contents(public_path('css/clinica-documento.css'));
        $this->assertStringContainsString('@page documento-media-vertical { size: 210mm 148.5mm; margin: 0; }', $css);
    }

    public function test_la_media_horizontal_nunca_usa_la_pagina_completa_y_la_siguiente_hoja_tambien_es_la_mitad(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->informe($medico, collect(range(1, 500))->map(fn ($n) => 'palabra' . $n)->implode(' '));

        RecipeFormato::create([
            'reg_medico' => $medico->reg_medico,
            'hojas_de_los_imprimibles' => [DocumentoImpreso::INFORME => HojaDelImprimible::MEDIA_HORIZONTAL],
        ]);

        $html = $this->como($user, $medico, $gineco)->get($this->url($consulta, DocumentoImpreso::INFORME))->assertOk()->getContent();

        $hojas = substr_count($html, 'class="recipe-hoja ');
        $this->assertGreaterThan(1, $hojas, 'el texto largo sigue en otra hoja');
        $this->assertSame($hojas, substr_count($html, 'recipe-hoja-horizontal'), 'todas las hojas son la mitad izquierda');
        $this->assertStringNotContainsString('recipe-hoja-completa', $html, 'nunca sale la página completa');
        $this->assertStringNotContainsString('recipe-mitad-vacia', $html);
        $this->assertSame(1, substr_count($html, 'class="firmas"'), 'la firma va sólo en la última mitad');
        $this->assertSame(1, substr_count($html, 'class="documento-continua"'));
    }

    public function test_la_hoja_en_blanco_sale_en_la_misma_hoja_que_el_documento(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        RecipeFormato::create([
            'reg_medico' => $medico->reg_medico,
            'hojas_de_los_imprimibles' => [DocumentoImpreso::CONSTANCIA => HojaDelImprimible::MEDIA_VERTICAL],
        ]);

        $html = $this->como($user, $medico, $gineco)
            ->get(route('clinica.formato.en-blanco', ['formato' => DocumentoImpreso::CONSTANCIA]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('recipe-hoja recipe-hoja-media-vertical', $html);
        $this->assertStringNotContainsString('recipe-mitad-vacia', $html);
    }

    public function test_el_recipe_sigue_en_media_hoja_horizontal_y_sin_el_boton(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-06');

        RecipeFormato::create([
            'reg_medico' => $medico->reg_medico,
            'hojas_de_los_imprimibles' => [DocumentoImpreso::CONSTANCIA => HojaDelImprimible::MEDIA_VERTICAL],
        ]);

        $html = $this->como($user, $medico, $gineco)->get($this->urlRecipe($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('class="recipe-hoja ', $html);
        $this->assertStringNotContainsString('recipe-hoja-completa', $html);
        $this->assertStringNotContainsString('recipe-hoja-media-vertical', $html);
        $this->assertStringNotContainsString('data-abrir-hoja', $html, 'el récipe no ofrece cambiar la hoja');
    }

    // ==================================================================== Desde la cita (secretaría)

    public function test_la_secretaria_cambia_la_hoja_desde_la_cita_y_queda_en_la_fila_del_doctor(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->constancia($medico);
        $secretaria = $this->secretariaConAcceso($medico);
        $cita = $this->cita($medico, 1001, $this->hoy->toDateString(), ['atendido' => 1]);

        $this->como($secretaria, $medico, $gineco)
            ->post(route('clinica.agenda.documentos.hoja', $cita->id), [
                'documento' => DocumentoImpreso::CONSTANCIA,
                'hoja' => HojaDelImprimible::MEDIA_HORIZONTAL,
            ])
            ->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::CONSTANCIA]));

        $this->assertSame(
            [DocumentoImpreso::CONSTANCIA => HojaDelImprimible::MEDIA_HORIZONTAL],
            $this->guardadas($medico),
            'la hoja se guarda en la fila del doctor que firma, aunque la cambie la secretaría',
        );

        $html = $this->como($secretaria, $medico, $gineco)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::CONSTANCIA]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('recipe-hoja recipe-hoja-horizontal', $html);
        $this->assertStringNotContainsString('recipe-hoja-completa', $html);
    }

    // ===================================================================== El modo de la hoja

    public function test_la_hoja_actual_es_la_de_siempre_si_el_medico_no_eligio(): void
    {
        $this->assertSame(HojaDelImprimible::COMPLETA, HojaDelImprimible::actual(null, DocumentoImpreso::CONSTANCIA));
        $this->assertSame(HojaDelImprimible::MEDIA_HORIZONTAL, HojaDelImprimible::actual(null, DocumentoImpreso::RESUMEN_MEDICO));
        $this->assertSame(HojaDelImprimible::MEDIA_VERTICAL, HojaDelImprimible::actual(null, DocumentoImpreso::INFORME, 'pequena'));
        $this->assertSame(
            HojaDelImprimible::MEDIA_VERTICAL,
            HojaDelImprimible::actual([DocumentoImpreso::CONSTANCIA => HojaDelImprimible::MEDIA_VERTICAL], DocumentoImpreso::CONSTANCIA),
        );
        // La página pequeña histórica manda sobre lo guardado.
        $this->assertSame(
            HojaDelImprimible::COMPLETA,
            HojaDelImprimible::actual([DocumentoImpreso::CONSTANCIA => HojaDelImprimible::MEDIA_VERTICAL], DocumentoImpreso::CONSTANCIA, 'grande'),
        );
        // Y lo que no es de las tres se descarta.
        $this->assertSame([], HojaDelImprimible::normalizar(['constancia' => 'carta', 'cosa' => 'completa']));
    }

    public function test_cada_hoja_elige_el_modo_del_motor(): void
    {
        $this->assertSame(ModoDeLaHoja::COMPLETA, HojaDelImprimible::modo(HojaDelImprimible::COMPLETA));
        $this->assertSame(ModoDeLaHoja::MEDIA_VERTICAL, HojaDelImprimible::modo(HojaDelImprimible::MEDIA_VERTICAL));
        $this->assertSame(ModoDeLaHoja::HORIZONTAL, HojaDelImprimible::modo(HojaDelImprimible::MEDIA_HORIZONTAL));
        $this->assertNull(HojaDelImprimible::modoDe(null, DocumentoImpreso::ECO_PELVICO), 'un eco no acepta la elección');
        $this->assertNull(HojaDelImprimible::modoDe(null, 'recipe'), 'un récipe tampoco');
    }
}
