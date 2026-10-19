<?php

namespace Tests\Feature;

use App\Models\Consulta;
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
 * **Neumología pediátrica** (postgrado, 2026-10-19): la solapa dentro de *Evaluación física*.
 *
 * Lo que se fija, contra el escritorio:
 *  - la solapa está **sólo con el postgrado** (sin él, el examen pediátrico sigue con sus tres solapas);
 *  - muestra los tres informes en el orden del escritorio (R.X. de tórax, espirometría, broncoscopía);
 *  - guarda cada uno en **su** columna de `consultas` —`rayosx`, `faringe` (la espirometría, que comparte columna
 *    con la citología de ginecología) y `broncoscopia`— con su rastro por columna;
 *  - la secretaría no puede guardar.
 */
class ConsultaNeumologiaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'neumo-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'neumo-' . substr(uniqid(), -6) . '@example.com',
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

    private function conNeumologia(Medico $medico): void
    {
        $neumo = Subspecialty::where('slug', 'neumologia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach($neumo->id);
    }

    private function url(Consulta $consulta, string $pestana = 'neumologia'): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'evaluacion', 'pestana' => $pestana]);
    }

    public function test_la_solapa_existe_solo_con_el_postgrado(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin el postgrado, las solapas de pediatría son las de siempre.
        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta, 'mediciones'))
            ->assertOk()->assertDontSee('Neumología');

        $this->conNeumologia($medico);

        $this->conContexto($user, $medico->fresh(), $pediatria)->get($this->url($consulta, 'mediciones'))
            ->assertOk()->assertSee('Neumología');

        // Y la solapa abre con sus tres informes, en el orden del escritorio.
        $this->conContexto($user, $medico->fresh(), $pediatria)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('R.X. de tórax')->assertSee('Espirometría')->assertSee('Broncoscopía')
            ->assertSee('Sin cargar');
    }

    public function test_guardar_escribe_cada_informe_en_su_columna(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNeumologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico->fresh(), $pediatria)
            ->post(route('clinica.consulta.neumologia.guardar', $consulta->id), [
                'rayosx'       => 'Tórax sin consolidaciones.',
                'espirometria' => 'Patrón obstructivo leve.',
                'broncoscopia' => 'Vía aérea permeable.',
            ])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHas('estado');

        $fila = $consulta->fresh();
        $this->assertSame('Tórax sin consolidaciones.', (string) $fila->rayosx);
        // La espirometría va a `faringe`: es la columna que el escritorio usa para la citología de ginecología.
        $this->assertSame('Patrón obstructivo leve.', (string) $fila->faringe);
        $this->assertSame('Vía aérea permeable.', (string) $fila->broncoscopia);

        // Un rastro por columna, con `source = web`.
        foreach (['rayosx', 'faringe', 'broncoscopia'] as $columna) {
            $rastro = SyncChange::where('table_name', 'consultas')
                ->where('record_id', $consulta->id)
                ->where('column_name', $columna)
                ->first();

            $this->assertNotNull($rastro, 'Falta el rastro de ' . $columna . '.');
            $this->assertSame('web', $rastro->source);
        }

        // Y la pantalla lo muestra.
        $this->conContexto($user, $medico->fresh(), $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Cargado')->assertSee('Patrón obstructivo leve.');
    }

    public function test_solo_guarda_lo_que_cambio(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNeumologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['rayosx' => 'Sin cambios.'])->save();

        $this->conContexto($user, $medico->fresh(), $pediatria)
            ->post(route('clinica.consulta.neumologia.guardar', $consulta->id), [
                'rayosx' => 'Sin cambios.', 'espirometria' => '', 'broncoscopia' => '',
            ])
            ->assertSessionHas('estado');

        // El texto quedó y no hay rastro del que no cambió.
        $this->assertSame('Sin cambios.', (string) $consulta->fresh()->rayosx);
        $this->assertNull(SyncChange::where('table_name', 'consultas')
            ->where('record_id', $consulta->id)->where('column_name', 'rayosx')->first());
    }

    public function test_la_secretaria_no_puede_guardar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNeumologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'secn-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');

        MedicoRegistro::where('medico_id', $medico->id)
            ->where('reg_medico', $medico->reg_medico)
            ->update(['user_id' => $secretaria->id]);

        $this->conContexto($secretaria, $medico, $pediatria)
            ->post(route('clinica.consulta.neumologia.guardar', $consulta->id), ['rayosx' => 'Algo'])
            ->assertForbidden();

        $this->assertNull($consulta->fresh()->rayosx);
    }
}
