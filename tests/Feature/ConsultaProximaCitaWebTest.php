<?php

namespace Tests\Feature;

use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Agendar la próxima cita desde el récipe** (2026-10-28): el formulario mínimo de la franja de la sección de
 * récipes y lo que eso deja en la hoja impresa.
 *
 * Lo que estos casos fijan:
 *
 *  - La franja ofrece **«Agendar aquí»** —un `<details>` con un `<form>` normal, sin JavaScript— además de «Hacer
 *    cita» (el formulario completo de la agenda), y también en el **editor** del récipe, que es donde el médico
 *    está cuando termina la consulta.
 *  - El alta va a la **misma ruta de la agenda** (`clinica.agenda.crear`) con `volver_consulta`: no hay una regla
 *    de agendamiento nueva.
 *  - **Desde el récipe la cita es de mañana en adelante**: es la misma condición con la que la hoja imprime la
 *    próxima cita (`fecha > hoy`), así que agendar para hoy dejaría la hoja sin la línea y se rechaza con un aviso
 *    en español. El formulario completo de la agenda no cambia.
 *  - La cita creada **sale al pie de la hoja** como `Próxima cita: 03/11/2026`.
 *  - En una sede **por hora** la hora es obligatoria (la hoja la imprime); en una por orden de llegada no.
 */
class ConsultaProximaCitaWebTest extends TestCase
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

    /** El mismo escenario de la impresión: médico, paciente con consulta de hoy y un récipe. */
    private function escenario(string $modalidad = Office::MODALIDAD_ORDEN): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->membrete($medico);
        $sede = $this->sede($medico, $modalidad);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, ['fnacimiento' => '1990-10-06']);
        $this->recipe($medico, 1001, 1, 1, ['0004'], '2026-10-05');

        return [$user, $medico, $gineco, $pacienteId, $consulta, $sede];
    }

    private function urlDeLaSeccion(int $consultaId, bool $lista = false): string
    {
        $parametros = ['seccion' => 'recipes'] + ($lista ? ['lista' => 1] : []);

        return route('clinica.consulta', [$consultaId] + $parametros);
    }

    // --------------------------------------------------------------------------- El formulario en la franja

    public function test_la_franja_ofrece_agendar_la_proxima_cita_en_el_lugar_junto_al_formulario_completo(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDeLaSeccion($consulta->id, true));

        $respuesta->assertOk();
        // Las dos formas de agendar conviven: la de acá y el formulario completo de siempre.
        $respuesta->assertSee('Agendar aquí');
        $respuesta->assertSee('Hacer cita');
        $respuesta->assertSee('action="' . route('clinica.agenda.crear') . '"', false);
        // Lo que el formulario necesita para no volver a preguntarlo: paciente, consulta de vuelta y la marca de origen.
        $respuesta->assertSee('name="paciente_id" value="' . $pacienteId . '"', false);
        $respuesta->assertSee('name="volver_consulta" value="' . $consulta->id . '"', false);
        $respuesta->assertSee('name="origen" value="proxima-cita"', false);
        // La fecha mínima es **mañana** (hoy es el 2026-10-07 en estas pruebas).
        $respuesta->assertSee('min="2026-10-08"', false);
        // Y lo que sale al pie de la hoja, que es el motivo del formulario.
        $respuesta->assertSee('no tiene próxima cita agendada');
    }

    public function test_la_franja_con_el_formulario_tambien_esta_en_el_editor_del_recipe(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDeLaSeccion($consulta->id));

        $respuesta->assertOk();
        $respuesta->assertSee('Agendar aquí');
        $respuesta->assertSee('name="origen" value="proxima-cita"', false);
    }

    public function test_sin_sedes_vigentes_la_franja_deja_solo_el_formulario_completo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->recipe($medico, 1001, 1, 1, ['0004'], '2026-10-05');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDeLaSeccion($consulta->id, true));

        $respuesta->assertOk();
        $respuesta->assertDontSee('Agendar aquí');
        $respuesta->assertSee('Hacer cita');
    }

    public function test_la_secretaria_no_entra_a_la_consulta_ni_ve_el_formulario(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlDeLaSeccion($consulta->id, true))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------------- El alta

    public function test_agendar_desde_el_recipe_crea_la_cita_y_la_hoja_la_imprime_al_pie(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta, $sede] = $this->escenario();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.agenda.crear'), [
            'origen'          => 'proxima-cita',
            'paciente_id'     => $pacienteId,
            'volver_consulta' => $consulta->id,
            'sede'            => $sede->id,
            'fecha'           => '2026-11-03',
            'hora'            => '10:30',
            'motivo'          => '',
        ]);

        // Vuelve a la sección de récipes, no a la agenda.
        $respuesta->assertRedirect();
        $this->assertStringContainsString('/clinica/consulta/' . $consulta->id, $respuesta->headers->get('Location'));
        $this->assertStringContainsString('seccion=recipes', $respuesta->headers->get('Location'));

        // La cita quedó en `cola` con la fecha pedida y el paciente de la consulta.
        $cita = DB::table('cola')
            ->where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', 1001)
            ->whereDate('fecha', '2026-11-03')
            ->first();
        $this->assertNotNull($cita, 'No se creó la cita del 03/11/2026.');
        $this->assertSame('10:30', (string) $cita->hora_ini);

        // Y la hoja del récipe la imprime al pie, que es lo que pidió el médico.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlRecipe($consulta))
            ->assertOk()
            ->assertSee('Próxima cita: 03/11/2026');
    }

    public function test_desde_el_recipe_no_se_puede_agendar_para_hoy_y_se_avisa_en_espanol(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta, $sede] = $this->escenario();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.agenda.crear'), [
            'origen'          => 'proxima-cita',
            'paciente_id'     => $pacienteId,
            'volver_consulta' => $consulta->id,
            'sede'            => $sede->id,
            'fecha'           => '2026-10-07',
        ]);

        $respuesta->assertSessionHasErrors('fecha');
        $mensaje = session('errors')->first('fecha');
        $this->assertSame('La próxima cita del récipe es a partir de mañana: elige un día posterior a hoy.', $mensaje);

        // Nada se escribió: la cita de hoy de la consulta es la que ya existía (ninguna, en este escenario).
        $this->assertSame(0, DB::table('cola')->where('reg_medico', $medico->reg_medico)->whereDate('fecha', '2026-10-07')->count());
    }

    public function test_el_formulario_completo_de_la_agenda_sigue_agendando_para_hoy(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta, $sede] = $this->escenario();

        // Sin `origen`: es el camino de «Hacer cita» y del mostrador, y ahí atender a alguien hoy es legítimo.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.agenda.crear'), [
            'paciente_id'     => $pacienteId,
            'volver_consulta' => $consulta->id,
            'sede'            => $sede->id,
            'fecha'           => '2026-10-07',
            'hora'            => '09:00',
        ]);

        $respuesta->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('cola')
            ->where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', 1001)
            ->whereDate('fecha', '2026-10-07')
            ->count());
    }

    public function test_en_una_sede_por_hora_la_hora_es_obligatoria_y_se_dice_sin_ingles(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta, $sede] = $this->escenario(Office::MODALIDAD_HORA);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.agenda.crear'), [
            'origen'          => 'proxima-cita',
            'paciente_id'     => $pacienteId,
            'volver_consulta' => $consulta->id,
            'sede'            => $sede->id,
            'fecha'           => '2026-11-03',
        ]);

        $respuesta->assertSessionHasErrors();
        $errores = collect(session('errors')->all());
        $this->assertNotEmpty($errores);
        foreach ($errores as $error) {
            $this->assertDoesNotMatchRegularExpression('/\b(field|required|must|invalid)\b/i', $error, $error);
            $this->assertStringContainsString('hora', mb_strtolower($error), $error);
        }
    }

    public function test_en_una_sede_por_hora_la_hoja_imprime_la_hora_de_la_cita(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta, $sede] = $this->escenario(Office::MODALIDAD_HORA);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.agenda.crear'), [
            'origen'          => 'proxima-cita',
            'paciente_id'     => $pacienteId,
            'volver_consulta' => $consulta->id,
            'sede'            => $sede->id,
            'fecha'           => '2026-11-03',
            'hora'            => '10:30',
        ])->assertSessionHasNoErrors();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlRecipe($consulta))
            ->assertOk()
            ->assertSee('Próxima cita: 03/11/2026  10:30');
    }

    public function test_si_el_paciente_ya_tiene_cita_pendiente_se_pregunta_si_moverla_y_la_vuelta_sigue_siendo_los_recipes(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta, $sede] = $this->escenario();
        $this->cita($medico, 1001, '2026-10-20', ['medical_center_id' => $sede->medical_center_id]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.agenda.crear'), [
            'origen'          => 'proxima-cita',
            'paciente_id'     => $pacienteId,
            'volver_consulta' => $consulta->id,
            'sede'            => $sede->id,
            'fecha'           => '2026-11-03',
            'hora'            => '10:30',
        ]);

        // No se escribe nada todavía: se muestra el formulario con la pregunta y la vuelta a los récipes.
        $respuesta->assertOk();
        $respuesta->assertSee('Ya tiene una cita pendiente');
        $respuesta->assertSee('name="volver_consulta" value="' . $consulta->id . '"', false);
        $this->assertNull(DB::table('cola')->where('reg_medico', $medico->reg_medico)->whereDate('fecha', '2026-11-03')->first());
    }

    /** La fecha mínima del control es la de mañana del consultorio, no la del reloj del servidor en UTC. */
    public function test_la_fecha_minima_es_manana_en_la_zona_del_consultorio(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        // 22:30 en Caracas del 7 = 02:30 UTC del 8: el «hoy» del consultorio sigue siendo el 7.
        Carbon::setTestNow(Carbon::parse('2026-10-08 02:30:00'));

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDeLaSeccion($consulta->id, true));

        $respuesta->assertSee('min="2026-10-08"', false);
        $respuesta->assertDontSee('min="2026-10-09"', false);
    }
}
