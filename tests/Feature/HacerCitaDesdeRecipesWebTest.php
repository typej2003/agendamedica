<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **"Hacer cita" desde los récipes de la consulta** (WEB-5.2): el botón de la sección de récipes lleva al formulario de
 * cita **que ya existe** (WEB-2.2) con el paciente ya elegido, y al guardar **vuelve a los récipes**.
 *
 * No hay lógica de agenda nueva: lo que estos casos fijan es el **retorno** —que el parámetro `volver_consulta` sólo
 * sirve a quien puede entrar a esa consulta, que no se puede usar para espiar la consulta de otro médico ni para
 * redirigir a otro lado y que la agenda se comporta como siempre cuando no hay retorno— y que el paciente llegue
 * elegido.
 */
class HacerCitaDesdeRecipesWebTest extends TestCase
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

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:int, 4:\App\Models\Consulta} */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-07');

        return [$user, $medico, $gineco, $pacienteId, $consulta];
    }

    private function urlRecipes(\App\Models\Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes']);
    }

    private function urlNueva(int $pacienteId, $volver): string
    {
        return route('clinica.agenda.nueva', ['paciente_id' => $pacienteId, 'volver_consulta' => $volver, 'fecha' => '2026-10-14']);
    }

    public function test_el_formulario_llega_con_el_paciente_elegido_y_el_regreso_a_los_recipes(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();
        $this->sede($medico, 'hora_cita');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlNueva($pacienteId, $consulta->id));

        $respuesta->assertOk();
        // El paciente ya está elegido (el formulario no lo vuelve a pedir) y se ve su nombre.
        $respuesta->assertSee('name="paciente_id"', false);
        $respuesta->assertSee('value="' . $pacienteId . '"', false);
        $respuesta->assertSee('Alvarez');
        // El regreso viaja en los dos formularios (el del paso 1 y el que guarda).
        $this->assertSame(2, substr_count($respuesta->getContent(), 'name="volver_consulta" value="' . $consulta->id . '"'));
        // Y el enlace de salida lleva a los récipes, no a la agenda.
        $respuesta->assertSee('href="' . $this->urlRecipes($consulta) . '"', false);
        $respuesta->assertSee('Volver a los récipes');
        $respuesta->assertDontSee('Volver a la agenda');
    }

    public function test_al_guardar_la_cita_vuelve_a_los_recipes_de_la_consulta(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();
        $sede = $this->sede($medico, 'hora_cita');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.crear'), [
                'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '09:30',
                'paciente_id' => $pacienteId, 'volver_consulta' => $consulta->id,
            ])
            ->assertRedirect($this->urlRecipes($consulta))
            ->assertSessionHas('estado', 'Cita agendada.');

        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame(1001, (int) $cita->numhistoria);
        $this->assertSame('2026-10-14', $cita->fecha->toDateString());

        // Y la hoja del récipe ya la imprime como próxima cita.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.recipes.imprimir', [$consulta->id, 1]))
            ->assertSee('Próxima cita: 14/10/2026  09:30');
    }

    public function test_si_el_paciente_ya_tiene_cita_pendiente_la_pregunta_conserva_el_regreso(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();
        $sede = $this->sede($medico, 'hora_cita');
        $this->cita($medico, 1001, '2026-10-20', ['medical_center_id' => $sede->medical_center_id]);

        $pregunta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.crear'), [
                'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '09:30',
                'paciente_id' => $pacienteId, 'volver_consulta' => $consulta->id,
            ]);

        $pregunta->assertOk();
        $pregunta->assertSee('Ya tiene una cita pendiente');
        $this->assertGreaterThanOrEqual(1, substr_count($pregunta->getContent(), 'name="volver_consulta" value="' . $consulta->id . '"'));

        // Resolver la pregunta (mover la cita) también vuelve a los récipes.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.crear'), [
                'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '09:30', 'decision' => 'mover',
                'paciente_id' => $pacienteId, 'volver_consulta' => $consulta->id,
            ])
            ->assertRedirect($this->urlRecipes($consulta));
    }

    public function test_sin_regreso_el_formulario_y_el_guardado_son_los_de_siempre(): void
    {
        [$user, $medico, $gineco, $pacienteId] = $this->escenario();
        $sede = $this->sede($medico, 'hora_cita');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.agenda.nueva', ['paciente_id' => $pacienteId, 'fecha' => '2026-10-14']))
            ->assertSee('Volver a la agenda')
            ->assertDontSee('volver_consulta', false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.crear'), ['sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '09:30', 'paciente_id' => $pacienteId])
            ->assertRedirect(route('clinica.agenda', ['vista' => 'dia', 'fecha' => '2026-10-14', 'sede' => $sede->id]));
    }

    public function test_un_regreso_a_la_consulta_de_otro_medico_se_ignora(): void
    {
        [$user, $medico, $gineco, $pacienteId] = $this->escenario();
        $sede = $this->sede($medico, 'hora_cita');
        [$otroUser, $otroMedico] = $this->medicoConAcceso();
        [, $consultaAjena] = $this->pacienteYConsulta($otroMedico, 2001, 1);

        // Ni el formulario ni el guardado la reconocen: vuelve a la agenda como siempre.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlNueva($pacienteId, $consultaAjena->id))
            ->assertOk()
            ->assertSee('Volver a la agenda')
            ->assertDontSee('volver_consulta', false)
            ->assertDontSee('seccion=recipes', false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.crear'), [
                'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '09:30',
                'paciente_id' => $pacienteId, 'volver_consulta' => $consultaAjena->id,
            ])
            ->assertRedirect(route('clinica.agenda', ['vista' => 'dia', 'fecha' => '2026-10-14', 'sede' => $sede->id]));
    }

    public function test_un_regreso_que_no_es_un_numero_no_redirige_a_ningun_lado(): void
    {
        [$user, $medico, $gineco, $pacienteId] = $this->escenario();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.agenda.nueva', ['paciente_id' => $pacienteId, 'volver_consulta' => 'https://malo.example.com']))
            ->assertOk()
            ->assertDontSee('malo.example.com', false)
            ->assertSee('Volver a la agenda');
    }

    public function test_la_secretaria_no_tiene_a_donde_volver_y_el_formulario_la_trata_como_siempre(): void
    {
        [, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();
        $sede = $this->sede($medico, 'hora_cita');
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlNueva($pacienteId, $consulta->id))
            ->assertOk()
            ->assertSee('Volver a la agenda')
            ->assertDontSee('volver_consulta', false);

        // Guardar con el parámetro a mano no la manda a una consulta que no puede abrir.
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.agenda.crear'), [
                'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '09:30',
                'paciente_id' => $pacienteId, 'volver_consulta' => $consulta->id,
            ])
            ->assertRedirect(route('clinica.agenda', ['vista' => 'dia', 'fecha' => '2026-10-14', 'sede' => $sede->id]));
    }

    public function test_el_regreso_con_un_valor_invalido_avisa_en_espanol(): void
    {
        [$user, $medico, $gineco, $pacienteId] = $this->escenario();
        $sede = $this->sede($medico, 'hora_cita');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.crear'), [
                'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '09:30',
                'paciente_id' => $pacienteId, 'volver_consulta' => 'abc',
            ]);

        $respuesta->assertSessionHasErrors('volver_consulta');
        $this->assertStringContainsString('No se sabe a qué consulta volver', session('errors')->first('volver_consulta'));
    }
}
