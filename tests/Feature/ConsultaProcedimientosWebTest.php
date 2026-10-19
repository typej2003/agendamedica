<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\EndoscopiaInferior;
use App\Models\EndoscopiaSuperior;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
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
 * **Procedimientos** (gastroenterología pediátrica, 2026-10-19): el ítem 8 del árbol de PediaReport.
 *
 * Lo que se fija:
 *  - la sección sólo existe con el postgrado de gastroenterología;
 *  - muestra las dos endoscopías como pestañas y abre con lo guardado;
 *  - guardar escribe **su** tabla (`endosc_diges_super` / `endosc_diges_infer`), una fila por consulta, **sólo lo que
 *    cambió** y con el rastro por columna (`source = web`); el estado va como un carácter;
 *  - la secretaría no puede guardar.
 */
class ConsultaProcedimientosWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'endo-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'endo-' . substr(uniqid(), -6) . '@example.com',
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

    private function conGastro(Medico $medico): void
    {
        $gastro = Subspecialty::where('slug', 'gastroenterologia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach($gastro->id);
    }

    private function url(Consulta $consulta, string $pestana = 'superior'): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'procedimientos', 'pestana' => $pestana]);
    }

    public function test_la_seccion_solo_existe_con_el_postgrado_de_gastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Elige una sección en el menú');

        $this->conGastro($medico);

        $this->conContexto($user, $medico->fresh(), $pediatria)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Endoscopía digestiva superior')->assertSee('Endoscopía digestiva inferior')
            ->assertSee('Sin cargar');
    }

    public function test_la_pantalla_abre_con_lo_guardado(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        EndoscopiaSuperior::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'equipo' => 'Olympus', 'descripcion' => 'Mucosa normal.', 'full_texto' => 'ESTUDIO NORMAL',
        ]);

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Cargada')
            ->assertSee('value="Olympus"', false)->assertSee('Mucosa normal.');
    }

    public function test_guardar_escribe_la_superior_con_su_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.procedimientos.guardar', [$consulta->id, 'superior']), [
                'equipo' => 'Olympus', 'descripcion' => 'Esófago, estómago y duodeno normales.',
                'conclusiones' => 'Estudio normal.', 'full_texto' => 'ESTUDIO NORMAL',
            ])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHas('estado');

        $fila = EndoscopiaSuperior::where('historia', 1001)->where('consulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('Olympus', $fila->equipo);
        $this->assertSame($medico->reg_medico, $fila->reg_medico);

        foreach (['equipo', 'descripcion', 'conclusiones', 'full_texto'] as $columna) {
            $rastro = SyncChange::where('table_name', 'endosc_diges_super')
                ->where('record_id', $fila->id)->where('column_name', $columna)->first();
            $this->assertNotNull($rastro, 'Falta el rastro de ' . $columna . '.');
            $this->assertSame('web', $rastro->source);
        }
    }

    public function test_la_inferior_guarda_el_estado_de_un_caracter(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.procedimientos.guardar', [$consulta->id, 'inferior']), [
                'esofago_estado' => 'N', 'esofago_descripcion' => 'Normal.',
            ])
            ->assertSessionHas('estado');

        $fila = EndoscopiaInferior::where('historia', 1001)->where('consulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('N', $fila->esofago_estado);
        // La tabla del superior no se toca.
        $this->assertSame(0, EndoscopiaSuperior::count());
    }

    public function test_sin_datos_no_crea_la_fila(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.procedimientos.guardar', [$consulta->id, 'superior']), ['equipo' => ''])
            ->assertSessionHas('estado');

        $this->assertSame(0, EndoscopiaSuperior::count());
    }

    public function test_la_pestana_de_la_eco_abdominal_reusa_la_pantalla_del_estudio(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // El ítem «Procedimientos» del escritorio también abre `w_cargar_visualizar_eco_abdominal`: la tercera
        // pestaña pinta **la misma** pantalla del estudio, y el guardado vuelve a esta sección.
        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta, 'ecoabdominal'))
            ->assertOk()
            ->assertSee('Eco abdominal')
            ->assertSee('estudio-abdominal-form', false)
            ->assertSee('name="volver" value="procedimientos"', false);
    }

    public function test_la_secretaria_no_puede_guardar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
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
            ->post(route('clinica.consulta.procedimientos.guardar', [$consulta->id, 'superior']), ['equipo' => 'X'])
            ->assertForbidden();

        $this->assertSame(0, EndoscopiaSuperior::count());
    }
}
