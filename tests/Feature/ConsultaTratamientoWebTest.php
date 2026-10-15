<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El tratamiento de la consulta** (WEB-5.7): el ítem TRATAMIENTO del panel del legado (`tabpage_40`), un texto
 * libre —con las plantillas `formato_print` de tipo `TR`— que el escritorio guarda en `consultas.laringe`
 * (`tab_1.tabpage_40.mle_11.text`, `w_consulta_principal.srw:2762`). Es un apartado **distinto de los récipes**
 * (confirmado por Alexander) y es el «PLAN DE TRATAMIENTO» del resumen médico.
 *
 * Reusa la pantalla y la ruta de guardado de las solapas de texto del examen, pero **no es una solapa del examen**:
 * tiene su propio ítem del panel, no aparece entre las solapas de la evaluación y vuelve a su propia sección.
 */
class ConsultaTratamientoWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-15 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'tratamiento-test-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'tratamiento-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Tratamiento', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function consulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria, 'nroconsulta' => $nroconsulta,
            'fecha' => $this->hoy->toDateString(),
        ]);
    }

    private function urlSeccion(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_TRATAMIENTO]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.examen.texto.guardar', [$consulta->id, 'tratamiento']);
    }

    private function plantilla(Medico $medico, string $tipo, string $codigo, string $titulo, ?string $texto): void
    {
        DB::table('formato_print')->insert([
            'reg_medico' => $medico->reg_medico, 'codigo' => $codigo, 'titulo' => $titulo, 'descripcion' => $texto,
            'tipo' => $tipo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------------------------ El panel

    public function test_el_panel_ofrece_el_tratamiento_entre_los_recipes_y_las_solicitudes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.consulta', $consulta->id))->assertOk()->getContent();

        $this->assertStringContainsString('seccion=tratamiento', $html);
        $this->assertStringContainsString('13 de 14 disponibles', $html);

        // El orden del legado: RECIPES, TRATAMIENTO, SOLICITUDES.
        $this->assertGreaterThan(strpos($html, 'seccion=recipes'), strpos($html, 'seccion=tratamiento'));
        $this->assertLessThan(strpos($html, 'seccion=solicitudes'), strpos($html, 'seccion=tratamiento'));
    }

    // ------------------------------------------------------------------------------- La sección

    public function test_la_seccion_trae_su_formulario_y_su_aviso(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        DB::table('consultas')->where('id', $consulta->id)->update(['laringe' => 'Control en un mes.']);

        $html = $this->conContexto($user, $medico, $gineco)->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('name="tratamiento"', $html);
        $this->assertStringContainsString('tratamiento-form', $html);
        $this->assertStringContainsString('tratamiento-form-sin-guardar', $html);
        $this->assertStringContainsString('Control en un mes.', $html);
        $this->assertStringContainsString('Guardar el tratamiento', $html);
        // No es la evaluación física ni una solapa suya.
        $this->assertStringNotContainsString('evaluacion-form', $html);
        $this->assertStringNotContainsString('pestana=tratamiento', $html);
    }

    public function test_no_es_una_solapa_del_examen(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        // Pedirlo como solapa de la evaluación vuelve a la evaluación física: no es una de sus solapas.
        $html = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'evaluacion', 'pestana' => 'tratamiento']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('evaluacion-form', $html);
        $this->assertStringNotContainsString('name="tratamiento"', $html);
    }

    // ------------------------------------------------------------------------------------ Guardar

    public function test_guardar_escribe_laringe_con_su_rastro_y_vuelve_a_la_seccion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), ['tratamiento' => 'Reposo relativo. Control en 15 días.'])
            ->assertRedirect($this->urlSeccion($consulta))
            ->assertSessionHas('estado', 'Tratamiento guardado.');

        $this->assertSame('Reposo relativo. Control en 15 días.', $consulta->fresh()->laringe);

        $rastro = SyncChange::where('table_name', 'consultas')->where('record_id', $consulta->id)->get(['operation', 'column_name', 'source']);
        $this->assertCount(1, $rastro);
        $this->assertSame(['updated', 'laringe', 'web'], [$rastro[0]->operation, $rastro[0]->column_name, $rastro[0]->source]);
    }

    public function test_guardar_el_mismo_texto_no_deja_rastro_nuevo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $gineco)->post($this->urlGuardar($consulta), ['tratamiento' => 'Igual.']);
        $this->conContexto($user, $medico, $gineco)->post($this->urlGuardar($consulta), ['tratamiento' => 'Igual.'])
            ->assertSessionHas('estado', 'El tratamiento no tenía cambios.');

        $this->assertSame(1, SyncChange::where('table_name', 'consultas')->where('record_id', $consulta->id)->count());
    }

    public function test_dejarlo_vacio_borra_el_texto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        DB::table('consultas')->where('id', $consulta->id)->update(['laringe' => 'Algo.']);

        $this->conContexto($user, $medico, $gineco)->post($this->urlGuardar($consulta), ['tratamiento' => ''])->assertRedirect();

        $this->assertNull($consulta->fresh()->laringe);
    }

    // ------------------------------------------------------------------------------- Plantillas

    public function test_las_plantillas_de_tratamiento_se_ofrecen_y_las_de_otro_tipo_no(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->plantilla($medico, 'TR', 'TR01', 'Reposo y control', 'Reposo relativo y control en una semana.');
        $this->plantilla($medico, 'EV', 'EV01', 'Evolución cualquiera', 'Texto de la evolución.');
        $this->plantilla($medico, 'TR', 'TR02', 'Vacía', 'none');

        $html = $this->conContexto($user, $medico, $gineco)->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('Reposo y control', $html);
        $this->assertStringContainsString('Reposo relativo y control en una semana.', $html);
        $this->assertStringNotContainsString('Evolución cualquiera', $html);
        $this->assertStringNotContainsString('TR02', $html);
    }

    // ----------------------------------------------------------------------------- Resumen médico

    public function test_lo_escrito_sale_como_plan_de_tratamiento_en_el_resumen_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        DB::table('consultas')->where('id', $consulta->id)->update(['enfermedadactual' => 'Dolor de dos días.']);

        $this->conContexto($user, $medico, $gineco)->post($this->urlGuardar($consulta), ['tratamiento' => 'Control en un mes.']);

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'resumen-medico']))
            ->assertOk()->assertSee('PLAN DE TRATAMIENTO:')->assertSee('Control en un mes.');
    }

    // ------------------------------------------------------------------------------------ Permisos

    public function test_la_secretaria_recibe_403(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        [$secretaria] = $this->medicoConAcceso('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($secretaria, $medico, $gineco)->get($this->urlSeccion($consulta))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->post($this->urlGuardar($consulta), ['tratamiento' => 'x'])->assertForbidden();
    }

    public function test_una_consulta_de_otro_consultorio_es_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajena = $this->consulta($otro);

        $this->conContexto($user, $medico, $gineco)->get($this->urlSeccion($ajena))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->post($this->urlGuardar($ajena), ['tratamiento' => 'x'])->assertNotFound();
        $this->assertNull($ajena->fresh()->laringe);
    }

    public function test_el_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlGuardar($consulta), ['tratamiento' => 'x'])->assertRedirect(route('login'));
    }
}
