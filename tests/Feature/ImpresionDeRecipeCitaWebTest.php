<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La secretaría imprime el récipe desde la agenda** (WEB-5.2): el botón "Imprimir récipe" en la fila de una cita
 * **ya atendida**, que lleva a la misma hoja que imprime el médico **sin entrar a la consulta** (que es sólo del médico).
 *
 * Lo que estos casos fijan:
 *
 *  - **Cuándo se ofrece**: sólo en una cita atendida cuya consulta tiene récipes con medicamentos. No en una cita sin
 *    atender, sin récipes o que el escritorio movió.
 *  - **Qué ve la secretaría**: la lista de qué récipes tiene la cita —sin medicamentos, sin indicaciones y sin los datos
 *    de la pareja— y la hoja, que es lo único clínico que sale.
 *  - **Cuál se imprime**: con uno solo va directo a la hoja; con varios elige uno o imprime todos.
 *  - **Los límites**: la cita de otro médico da 404, la consulta y su hoja siguen dando 403 y el invitado va al login.
 *  - **La consulta de la cita**: la del día con el número más alto (la que abre "Editar consulta").
 *  - **Lo mismo que el médico**: la misma hoja y las mismas opciones.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ImpresionDeRecipeCitaWebTest extends TestCase
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

    /**
     * El médico, su secretaría y un paciente con una cita atendida hoy y su consulta con **un** récipe.
     *
     * @return array{0:User, 1:Medico, 2:Specialty, 3:User, 4:Cola, 5:Consulta}
     */
    private function escenario(): array
    {
        [$medicoUser, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->membrete($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, ['fnacimiento' => '1990-10-06']);
        $this->recipe($medico, 1001, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.'], ['0003', 1, 'Una al día.']], '2026-10-05');
        $cita = $this->cita($medico, 1001, '2026-10-07', ['atendido' => 1, 'estado' => 1]);

        return [$medicoUser, $medico, $gineco, $secretaria, $cita, $consulta];
    }

    private function agenda(User $user, Medico $medico, Specialty $gineco)
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=2026-10-07&sede=todas');
    }

    private function urlLista(Cola $cita): string
    {
        return route('clinica.agenda.documentos', $cita->id);
    }

    /** La vista previa de un récipe de la cita (`'1'`, `'pareja'`), de `'todos'` o —sin nada— del único que tenga. */
    private function urlHoja(Cola $cita, ?string $recipe = null): string
    {
        $documento = $recipe === null || $recipe === 'todos' ? $recipe : 'recipe-' . $recipe;

        return route('clinica.agenda.documentos.imprimir', $documento === null ? [$cita->id] : [$cita->id, 'documento' => $documento]);
    }

    // ---------------------------------------------------------------------------- El botón de la agenda

    public function test_la_agenda_le_ofrece_a_la_secretaria_imprimir_el_recipe_de_la_cita_atendida(): void
    {
        [, $medico, $gineco, $secretaria, $cita] = $this->escenario();

        $respuesta = $this->agenda($secretaria, $medico, $gineco);

        $respuesta->assertOk();
        $respuesta->assertSee('href="' . $this->urlLista($cita) . '"', false);
        $respuesta->assertSee('Imprimir documentos');
    }

    public function test_no_se_ofrece_en_una_cita_sin_atender_sin_recipes_o_movida_por_el_escritorio(): void
    {
        [, $medico, $gineco, $secretaria, $cita] = $this->escenario();

        // Atendida pero sin récipes.
        $this->pacienteYConsulta($medico, 1002, 1, ['nombres' => 'Beatriz', 'cedula' => '11222333']);
        $sinRecipes = $this->cita($medico, 1002, '2026-10-07', ['atendido' => 1, 'numorden' => 2]);
        // Con récipes pero sin atender.
        $this->pacienteYConsulta($medico, 1003, 1, ['nombres' => 'Carla', 'cedula' => '22333444']);
        $this->recipe($medico, 1003, 1, 1, ['0001'], '2026-10-05');
        $sinAtender = $this->cita($medico, 1003, '2026-10-07', ['atendido' => 0, 'numorden' => 3]);
        // Con récipes y atendida, pero la fila es la que el escritorio movió.
        $this->pacienteYConsulta($medico, 1004, 1, ['nombres' => 'Diana', 'cedula' => '33444555']);
        $this->recipe($medico, 1004, 1, 1, ['0001'], '2026-10-05');
        $movida = $this->cita($medico, 1004, '2026-10-07', ['atendido' => 1, 'movida_escritorio' => 1, 'numorden' => 4]);

        $respuesta = $this->agenda($secretaria, $medico, $gineco);

        $respuesta->assertSee('href="' . $this->urlLista($cita) . '"', false);
        foreach ([$sinRecipes, $sinAtender, $movida] as $otra) {
            $respuesta->assertDontSee('href="' . $this->urlLista($otra) . '"', false);
        }
    }

    public function test_el_medico_tambien_ve_el_boton_en_la_fila_de_la_cita_atendida(): void
    {
        [$medicoUser, $medico, $gineco, , $cita] = $this->escenario();

        $this->agenda($medicoUser, $medico, $gineco)->assertSee('href="' . $this->urlLista($cita) . '"', false);
    }

    public function test_la_consulta_de_la_cita_es_la_del_dia_con_el_numero_mas_alto(): void
    {
        [, $medico, $gineco, $secretaria, $cita, $consulta] = $this->escenario();

        // Una segunda consulta del mismo día ("consulta nueva"): "Editar consulta" abre ésta, sin récipes.
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => '2026-10-07']);

        $this->agenda($secretaria, $medico, $gineco)->assertDontSee('href="' . $this->urlLista($cita) . '"', false);

        // Con récipes en la última, el botón vuelve.
        $this->recipe($medico, 1001, 2, 1, ['0004'], '2026-10-07');
        $this->agenda($secretaria, $medico, $gineco)->assertSee('href="' . $this->urlLista($cita) . '"', false);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita, '1'))
            ->assertOk()
            ->assertSee('LOSARTAN / TABLETAS 50 MG')
            ->assertDontSee('KEFORAL');
    }

    // ----------------------------------------------------------------------------------- Un solo récipe

    public function test_con_un_solo_recipe_la_lista_se_salta_y_va_directo_a_la_hoja(): void
    {
        [, $medico, $gineco, $secretaria, $cita] = $this->escenario();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlLista($cita))
            ->assertRedirect($this->urlHoja($cita, '1'));

        $hoja = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita, '1'));

        $hoja->assertOk();
        $hoja->assertSee('Dra. Ana Imprime');
        $hoja->assertSee('Ana Alvarez');
        $hoja->assertSee('35 años');
        $hoja->assertSee('KEFORAL / CAP. 500 MG');
        $hoja->assertSee('Una cápsula cada 6 horas.');
        // Sin `?recipe=` imprime el único que tiene.
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita))
            ->assertOk()
            ->assertSee('KEFORAL / CAP. 500 MG');
    }

    public function test_la_secretaria_y_el_medico_imprimen_la_misma_hoja(): void
    {
        [$medicoUser, $medico, $gineco, $secretaria, $cita, $consulta] = $this->escenario();
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico, 'modo_impresion' => 'solo_indicaciones', 'nombre_medicamento' => 'generico',
            'color_linea' => 'azul', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $secretariaVe = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita, '1'));
        $medicoVe = $this->conContexto($medicoUser, $medico, $gineco)->actingAs($medicoUser)->get($this->urlRecipe($consulta, 1));

        foreach ([$secretariaVe, $medicoVe] as $respuesta) {
            $respuesta->assertOk();
            $respuesta->assertSee('CEFALEXINA / CAP. 500 MG');
            $respuesta->assertSee('linea-azul', false);
            $respuesta->assertDontSee('recipe-mitad-rp', false);
        }

        // El cuerpo de la hoja (todo lo que se imprime) es el mismo.
        $hoja = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));
        $this->assertSame($hoja($medicoVe->getContent()), $hoja($secretariaVe->getContent()));
    }

    // -------------------------------------------------------------------------------------- Varios récipes

    /** La cita con dos récipes del paciente y el de la pareja. */
    private function conVarios(): array
    {
        $datos = $this->escenario();
        $medico = $datos[1];
        $this->recipe($medico, 1001, 1, 2, [['0004', 30, 'Una en la mañana, por un mes.']], '2026-10-06');
        $this->pareja($medico, 1001, 1, ['0002'], '9876543', 'Mariana Rojas');

        return $datos;
    }

    public function test_con_varios_recipes_la_secretaria_elige_cual_imprimir_sin_ver_su_contenido(): void
    {
        [, $medico, $gineco, $secretaria, $cita] = $this->conVarios();

        $respuesta = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlLista($cita));

        $respuesta->assertOk();
        $respuesta->assertSee('Documentos de Ana Alvarez');
        $respuesta->assertSee('Récipe 1');
        $respuesta->assertSee('Récipe 2');
        $respuesta->assertSee('Récipe de la pareja');
        $respuesta->assertSee('2 medicamentos');
        $respuesta->assertSee('Imprimir todos');
        $respuesta->assertSee('data-siguiente="' . $this->urlHoja($cita, '2') . '"', false);
        $respuesta->assertSee('data-siguiente="' . $this->urlHoja($cita, 'pareja') . '"', false);
        $respuesta->assertSee('data-siguiente="' . $this->urlHoja($cita, 'todos') . '"', false);
        // El diálogo de las opciones, igual que el del médico.
        $respuesta->assertSee('id="dialogo-imprimir-recipe"', false);
        // **No** ve el contenido clínico: ni medicamentos, ni indicaciones, ni la pareja.
        foreach (['KEFORAL', 'LOSARTAN', 'DIFLUCAN', 'Una cápsula cada 6 horas', 'Mariana Rojas', '9876543', 'ÁCIDO'] as $clinico) {
            $respuesta->assertDontSee($clinico);
        }
    }

    public function test_se_imprime_uno_u_otro_o_todos(): void
    {
        [, $medico, $gineco, $secretaria, $cita] = $this->conVarios();
        $como = fn (string $recipe) => $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita, $recipe));

        $uno = $como('1');
        $uno->assertOk()->assertSee('KEFORAL / CAP. 500 MG')->assertDontSee('LOSARTAN')->assertDontSee('Mariana Rojas');
        $this->assertSame(1, $this->hojasDe($uno->getContent()));

        $dos = $como('2');
        $dos->assertOk()->assertSee('LOSARTAN / TABLETAS 50 MG')->assertDontSee('KEFORAL');

        $pareja = $como('pareja');
        $pareja->assertOk()->assertSee('Mariana Rojas')->assertSee('DIFLUCAN G / CAP. 150 MG')->assertDontSee('Ana Alvarez');

        $todos = $como('todos');
        $todos->assertOk()->assertSee('KEFORAL / CAP. 500 MG')->assertSee('LOSARTAN / TABLETAS 50 MG')->assertSee('DIFLUCAN G / CAP. 150 MG');
        $this->assertSame(3, $this->hojasDe($todos->getContent()));
        $todos->assertSee('3 récipes · 4 medicamentos · 3 hojas');
    }

    public function test_un_recipe_que_la_cita_no_tiene_o_sin_elegir_con_varios_da_404(): void
    {
        [, $medico, $gineco, $secretaria, $cita] = $this->conVarios();
        $como = fn (?string $recipe) => $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita, $recipe));

        $como('7')->assertNotFound();
        $como('abc')->assertNotFound();
        // Con varios no se adivina cuál: hay que elegir.
        $como(null)->assertNotFound();
    }

    // ------------------------------------------------------------------------- Sin nada que imprimir

    public function test_una_cita_atendida_sin_recipes_lo_dice_y_su_hoja_da_404(): void
    {
        [, $medico, $gineco, $secretaria] = $this->escenario();
        $this->pacienteYConsulta($medico, 1002, 1, ['nombres' => 'Beatriz', 'cedula' => '11222333']);
        $cita = $this->cita($medico, 1002, '2026-10-07', ['atendido' => 1, 'numorden' => 2]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlLista($cita))
            ->assertOk()
            ->assertSee('Esta consulta no tiene documentos para imprimir');

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita, '1'))->assertNotFound();
    }

    public function test_una_cita_sin_atender_lo_dice_y_no_imprime_nada(): void
    {
        [, $medico, $gineco, $secretaria] = $this->escenario();
        $this->pacienteYConsulta($medico, 1003, 1, ['nombres' => 'Carla', 'cedula' => '22333444']);
        $this->recipe($medico, 1003, 1, 1, ['0001'], '2026-10-05');
        $cita = $this->cita($medico, 1003, '2026-10-07', ['atendido' => 0, 'numorden' => 3]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlLista($cita))
            ->assertOk()
            ->assertSee('Esta cita todavía no fue atendida')
            ->assertDontSee('KEFORAL');

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlHoja($cita, '1'))->assertNotFound();
    }

    // --------------------------------------------------------------------------------------- Los límites

    public function test_la_cita_de_otro_medico_da_404(): void
    {
        [, , , , $cita] = $this->escenario();
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $otraSecretaria = $this->secretariaConAcceso($otroMedico);

        foreach ([[$otroUser, $otroMedico, $otraGineco], [$otraSecretaria, $otroMedico, $otraGineco]] as [$user, $suMedico, $suEspecialidad]) {
            $this->conContexto($user, $suMedico, $suEspecialidad)->actingAs($user)->get($this->urlLista($cita))->assertNotFound();
            $this->conContexto($user, $suMedico, $suEspecialidad)->actingAs($user)->get($this->urlHoja($cita, '1'))->assertNotFound();
        }

    }

    public function test_el_invitado_va_al_login(): void
    {
        [, , , , $cita] = $this->escenario();

        $this->get($this->urlLista($cita))->assertRedirect(route('login'));
        $this->get($this->urlHoja($cita, '1'))->assertRedirect(route('login'));
    }

    public function test_la_secretaria_imprime_pero_sigue_sin_poder_abrir_la_consulta(): void
    {
        [, $medico, $gineco, $secretaria, $cita, $consulta] = $this->escenario();
        $como = fn (string $url) => $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($url);

        $como($this->urlHoja($cita, '1'))->assertOk();
        $como(route('clinica.consulta', $consulta->id))->assertForbidden();
        $como(route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes']))->assertForbidden();
        $como($this->urlRecipe($consulta, 1))->assertForbidden();
        // Y la acción "Atender" sigue siendo sólo del médico.
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->post(route('clinica.agenda.atender', $cita->id))->assertForbidden();
    }

    public function test_la_secretaria_cambia_las_opciones_desde_la_hoja_y_el_medico_las_ve(): void
    {
        [$medicoUser, $medico, $gineco, $secretaria, $cita, $consulta] = $this->escenario();
        $siguiente = '/clinica/agenda/' . $cita->id . '/documentos/imprimir?documento=recipe-1';

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.recipes.opciones'), ['modo' => 'solo_indicaciones', 'nombre' => 'generico', 'siguiente' => $siguiente])
            ->assertRedirect($siguiente);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($siguiente)
            ->assertSee('Solo indicaciones · nombre genérico');
        $this->conContexto($medicoUser, $medico, $gineco)->actingAs($medicoUser)->get($this->urlRecipe($consulta, 1))
            ->assertSee('Solo indicaciones · nombre genérico')
            ->assertSee('CEFALEXINA / CAP. 500 MG');
    }
}
