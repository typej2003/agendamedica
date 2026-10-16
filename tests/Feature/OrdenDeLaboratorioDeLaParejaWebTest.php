<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La orden de laboratorio de la pareja** (WEB-11.21): el botón «Pareja» de la pestaña Laboratorio (el diálogo «Examen de la Pareja» del
 * escritorio, rediseñado). Lo que fijan estos casos:
 *
 *  - la orden se **guarda entera** (exámenes en `examen_pareja` con la C.I. y el nombre copiados, como el escritorio, y los datos y el resumen
 *    clínico en `examen_pareja_datos`) y **se recarga** como se dejó;
 *  - **sin C.I. y nombre no hay orden**, y un examen tiene que ser del catálogo;
 *  - **no se mezcla** con la orden de la paciente (otra tabla, otro resumen);
 *  - la hoja sale **a nombre de la pareja**.
 */
class OrdenDeLaboratorioDeLaParejaWebTest extends TestCase
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

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta} */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        DB::table('tipos_examenes')->insert(['reg_medico' => $medico->reg_medico, 'codetipo' => '001', 'tipo' => 'LABORATORIO', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['001', 'GLICEMIA'], ['002', 'HEMATOLOGÍA COMPLETA'], ['003', 'UREA']] as [$codigo, $nombre]) {
            DB::table('examenes')->insert(['reg_medico' => $medico->reg_medico, 'codeexamen' => $codigo, 'examen' => $nombre, 'codetipo' => '001', 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$user, $medico, $gineco, $consulta];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function pantalla(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'pareja']);
    }

    private function guardar(Consulta $consulta): string
    {
        return route('clinica.consulta.laboratorio-pareja.guardar', $consulta->id);
    }

    public function test_el_laboratorio_ofrece_el_boton_pareja_y_la_pantalla_abre_vacia(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'laboratorio']))
            ->assertOk()->assertSee('Pareja')->assertSee('solicitud=pareja', false);

        $this->como($user, $medico, $gineco)->get($this->pantalla($consulta))
            ->assertOk()
            ->assertSee('Exámenes de laboratorio de la pareja')
            ->assertSee('C.I. de la pareja')->assertSee('Nombre de la pareja')
            ->assertSee('Volver al laboratorio del paciente')
            ->assertSee('name="cedula"', false)->assertSee('name="nombre"', false);
    }

    public function test_guardar_crea_los_examenes_con_la_ci_y_el_nombre_los_datos_y_el_resumen_con_su_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post($this->guardar($consulta), [
            'cedula' => '12345678', 'nombre' => 'María Pérez', 'examenes' => ['001', '003'], 'observacion' => 'Descartar infección.',
        ])->assertRedirect($this->pantalla($consulta))->assertSessionHas('estado', 'Orden de la pareja guardada.');

        $filas = DB::table('examen_pareja')->where('reg_medico', $medico->reg_medico)->orderBy('codeexamen')->get();
        $this->assertSame(['001', '003'], $filas->pluck('codeexamen')->map(fn ($c) => trim($c))->all());
        foreach ($filas as $fila) {
            $this->assertSame(1001, (int) $fila->nrohistoria);
            $this->assertSame(1, (int) $fila->nroconsulta);
            $this->assertSame('12345678', $fila->cedula);
            $this->assertSame('María Pérez', $fila->nombre);
        }

        $datos = DB::table('examen_pareja_datos')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('Descartar infección.', $datos->observacion);
        $this->assertSame(2, SyncChange::where('table_name', 'examen_pareja')->where('operation', 'created')->where('source', 'web')->count());
        $this->assertSame(1, SyncChange::where('table_name', 'examen_pareja_datos')->where('operation', 'created')->where('source', 'web')->count());

        // No toca la orden de la paciente.
        $this->assertSame(0, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->count());

        // Al volver a abrirla, se encuentra como se dejó.
        $this->como($user, $medico, $gineco)->get($this->pantalla($consulta))
            ->assertOk()
            ->assertSee('value="12345678"', false)->assertSee('value="María Pérez"', false)
            ->assertSee('GLICEMIA')->assertSee('UREA')->assertSee('Descartar infección.')
            ->assertSee('Eliminar la orden de la pareja')
            ->assertSee(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'laboratorio-pareja']), false);
    }

    public function test_sin_cedula_y_nombre_no_hay_orden_y_el_formulario_vuelve_con_lo_escrito(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->from($this->pantalla($consulta))->post($this->guardar($consulta), [
            'cedula' => '', 'nombre' => 'María', 'examenes' => ['001'], 'observacion' => 'Lo escrito.',
        ])->assertSessionHasErrors('nombre');

        $this->assertSame(0, DB::table('examen_pareja')->count() - DB::table('examen_pareja')->where('reg_medico', '<>', $medico->reg_medico)->count());
        $this->assertNull(DB::table('examen_pareja_datos')->where('reg_medico', $medico->reg_medico)->first());
    }

    public function test_un_examen_que_no_es_del_catalogo_se_rechaza(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->from($this->pantalla($consulta))->post($this->guardar($consulta), [
            'cedula' => '1', 'nombre' => 'María', 'examenes' => ['999'],
        ])->assertSessionHasErrors('nombre');

        $this->assertNull(DB::table('examen_pareja_datos')->where('reg_medico', $medico->reg_medico)->first());
    }

    public function test_editar_quita_agrega_y_actualiza_la_ci_y_el_nombre_de_los_que_se_quedan(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $web = $this->como($user, $medico, $gineco);

        $web->post($this->guardar($consulta), ['cedula' => '1', 'nombre' => 'María', 'examenes' => ['001', '002']]);
        $web->post($this->guardar($consulta), ['cedula' => '2', 'nombre' => 'María José', 'examenes' => ['002', '003']])
            ->assertSessionHas('estado');

        $filas = DB::table('examen_pareja')->where('reg_medico', $medico->reg_medico)->orderBy('codeexamen')->get();
        $this->assertSame(['002', '003'], $filas->pluck('codeexamen')->map(fn ($c) => trim($c))->all());
        $this->assertSame(['2', '2'], $filas->pluck('cedula')->all());
        $this->assertSame(['María José', 'María José'], $filas->pluck('nombre')->all());
        $this->assertSame(1, DB::table('examen_pareja_datos')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('María José', DB::table('examen_pareja_datos')->where('reg_medico', $medico->reg_medico)->value('nombre'));
    }

    public function test_eliminar_borra_la_orden_de_la_pareja_y_no_la_de_la_paciente(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $web = $this->como($user, $medico, $gineco);

        $web->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['002'], 'observacion' => 'De la paciente.']);
        $web->post($this->guardar($consulta), ['cedula' => '1', 'nombre' => 'María', 'examenes' => ['001']]);

        $web->delete(route('clinica.consulta.laboratorio-pareja.eliminar', $consulta->id))
            ->assertRedirect($this->pantalla($consulta))->assertSessionHas('estado', 'Orden de la pareja eliminada.');

        $this->assertSame(0, DB::table('examen_pareja')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('examen_pareja_datos')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('De la paciente.', DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->value('observacion'));
    }

    public function test_la_hoja_sale_a_nombre_de_la_pareja_con_sus_examenes_y_el_resumen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $web = $this->como($user, $medico, $gineco);
        $web->post($this->guardar($consulta), ['cedula' => '12345678', 'nombre' => 'María Pérez', 'examenes' => ['001', '002'], 'observacion' => 'Resumen de la pareja.']);

        $hoja = $web->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'laboratorio-pareja']));

        $hoja->assertOk()
            ->assertSee('SOLICITUD DE EXÁMENES DE LABORATORIO')
            ->assertSee('PACIENTE:')->assertSee('María Pérez')
            ->assertSee('12345678')
            ->assertSee('GLICEMIA')->assertSee('HEMATOLOGÍA COMPLETA')
            ->assertSee('RESUMEN CLÍNICO:')->assertSee('Resumen de la pareja.');
    }

    public function test_la_secretaria_no_entra_y_otro_medico_recibe_404(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->post($this->guardar($consulta), ['cedula' => '1', 'nombre' => 'M', 'examenes' => ['001']])->assertForbidden();

        [$otroUser, $otro, $otroGineco] = $this->medicoConAcceso();
        $this->conContexto($otroUser, $otro, $otroGineco)->actingAs($otroUser)->post($this->guardar($consulta), ['cedula' => '1', 'nombre' => 'M', 'examenes' => ['001']])->assertNotFound();
        $this->assertSame(0, DB::table('examen_pareja_datos')->count());
    }
}
