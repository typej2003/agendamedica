<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\IntervencionConsulta;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PreAnestesico;
use App\Models\Specialty;
use App\Models\Subspecialty;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **I.Q.** (cirugía pediátrica, 2026-10-19): el último ítem del árbol de PediaReport.
 *
 * Lo que se fija:
 *  - la sección sólo existe con el postgrado de cirugía;
 *  - la evaluación pre-operatoria (`pre_aneste`, una fila por consulta) abre con lo guardado y se guarda con
 *    **sólo lo que cambió**, rastro por columna y `emergencia` de un carácter;
 *  - las otras dos pestañas se anuncian como pendientes;
 *  - la secretaría no puede guardar.
 */
class ConsultaQuirurgicoWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'iq-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'iq-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '2018-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => '1001',
        ]);

        Historia::create([
            'numhistoria' => '1001', 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        $consulta = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'fecha' => now()->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function conCirugia(Medico $medico): void
    {
        $cirugia = Subspecialty::where('slug', 'cirugia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach($cirugia->id);
    }

    private function url(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'quirurgico', 'pestana' => 'preoperatoria']);
    }

    public function test_la_seccion_solo_existe_con_el_postgrado_de_cirugia(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Elige una sección en el menú');

        $this->conCirugia($medico);

        $this->conContexto($user, $medico->fresh(), $pediatria)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Evaluación pre-operatoria')
            ->assertSee('Intervenciones')->assertSee('Riesgo quirúrgico')
            ->assertSee('(próximamente)')
            ->assertSee('Sin cargar');
    }

    public function test_la_pantalla_abre_con_lo_guardado_y_avisa_si_es_emergencia(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conCirugia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        PreAnestesico::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'tipo' => 'A', 'resultado' => 'Apto para cirugía.', 'emergencia' => 'S',
        ]);

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Emergencia')
            ->assertSee('Apto para cirugía.')
            ->assertSee('selected', false);
    }

    public function test_guardar_escribe_la_evaluacion_con_su_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conCirugia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.preoperatorio.guardar', $consulta->id), [
                'tipo' => 'A', 'resultado' => 'Apto para cirugía.', 'emergencia' => 'N',
            ])
            ->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'quirurgico', 'pestana' => 'preoperatoria']))
            ->assertSessionHas('estado');

        $fila = PreAnestesico::where('historia', 1001)->where('consulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('A', $fila->tipo);
        $this->assertSame('Apto para cirugía.', $fila->resultado);
        $this->assertSame('N', $fila->emergencia);

        foreach (['tipo', 'resultado', 'emergencia'] as $columna) {
            $rastro = SyncChange::where('table_name', 'pre_aneste')
                ->where('record_id', $fila->id)->where('column_name', $columna)->first();
            $this->assertNotNull($rastro, 'Falta el rastro de ' . $columna . '.');
            $this->assertSame('web', $rastro->source);
        }
    }

    public function test_sin_datos_no_crea_la_fila(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conCirugia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.preoperatorio.guardar', $consulta->id), ['resultado' => ''])
            ->assertSessionHas('estado');

        $this->assertSame(0, PreAnestesico::count());
    }

    public function test_la_pestana_de_intervenciones_lista_las_cargadas(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conCirugia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        IntervencionConsulta::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'codigo_inter' => 'A01', 'descripcion' => 'Herniorrafia inguinal', 'detalle' => 'Lado derecho.',
        ]);

        $this->conContexto($user, $medico, $pediatria)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'quirurgico', 'pestana' => 'intervenciones']))
            ->assertOk()
            ->assertSee('Intervenciones')
            ->assertSee('A01')->assertSee('Herniorrafia inguinal')->assertSee('Lado derecho.')
            ->assertSee('Cargada');
    }

    public function test_agregar_una_intervencion_la_crea_con_su_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conCirugia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.intervenciones.guardar', $consulta->id), [
                'codigo' => 'b02', 'descripcion' => 'Apendicectomía', 'detalle' => '',
            ])
            ->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'quirurgico', 'pestana' => 'intervenciones']))
            ->assertSessionHas('estado');

        $fila = IntervencionConsulta::where('historia', 1001)->first();
        $this->assertNotNull($fila);
        // El código se normaliza a mayúsculas (la columna es `char(3)`).
        $this->assertSame('B02', $fila->codigo_inter);
        $this->assertSame('Apendicectomía', $fila->descripcion);

        $rastro = SyncChange::where('table_name', 'interven_consulta')
            ->where('record_id', $fila->id)->where('operation', 'created')->first();
        $this->assertNotNull($rastro);
        $this->assertSame('web', $rastro->source);

        // El mismo código la corrige en el lugar y no duplica.
        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.intervenciones.guardar', $consulta->id), [
                'codigo' => 'B02', 'descripcion' => 'Apendicectomía laparoscópica', 'detalle' => 'Sin complicaciones.',
            ])
            ->assertSessionHas('estado');

        $this->assertSame(1, IntervencionConsulta::count());
        $this->assertSame('Apendicectomía laparoscópica', $fila->fresh()->descripcion);
    }

    public function test_quitar_una_intervencion_deja_su_baja(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conCirugia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        IntervencionConsulta::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'codigo_inter' => 'C03', 'descripcion' => 'Circuncisión',
        ]);

        $this->conContexto($user, $medico, $pediatria)
            ->delete(route('clinica.consulta.intervenciones.quitar', [$consulta->id, 'C03']))
            ->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'quirurgico', 'pestana' => 'intervenciones']))
            ->assertSessionHas('estado');

        $this->assertSame(0, IntervencionConsulta::count());
        $this->assertNotNull(SyncChange::where('table_name', 'interven_consulta')
            ->where('operation', 'deleted')->first());
    }

    public function test_la_secretaria_no_puede_guardar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conCirugia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'sec-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');

        MedicoRegistro::where('medico_id', $medico->id)
            ->where('reg_medico', $medico->reg_medico)
            ->update(['user_id' => $secretaria->id]);

        $this->conContexto($secretaria, $medico, $pediatria)
            ->post(route('clinica.consulta.preoperatorio.guardar', $consulta->id), ['tipo' => 'A'])
            ->assertForbidden();

        $this->assertSame(0, PreAnestesico::count());
    }
}
