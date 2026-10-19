<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\EndoscopiaSuperior;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\Subspecialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El informe impreso de las endoscopías digestivas** (gastroenterología pediátrica, 2026-10-19).
 *
 * Lo que se fija, contra el escritorio:
 *  - la hoja se **ofrece sólo si la endoscopía tiene algo que imprimir** (`full_texto` o, en su defecto, los
 *    hallazgos y la conclusión);
 *  - su cuerpo es ese texto, y lleva el título del documento (`d_endoscopia_superior_print_puro`);
 *  - sin texto no se ofrece, y tampoco hay hoja vacía.
 */
class ConsultaEndoscopiaImpresionWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'endo-imp-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'endo-imp-' . substr(uniqid(), -6) . '@example.com',
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

    public function test_la_hoja_imprime_el_texto_de_la_endoscopia(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        EndoscopiaSuperior::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'descripcion' => 'Mucosa normal.', 'full_texto' => 'ESTUDIO NORMAL: esófago, estómago y duodeno sin lesiones.',
        ]);

        $this->conContexto($user, $medico->fresh(), $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'endoscopia-superior']))
            ->assertOk()
            ->assertSee('ENDOSCOP')
            ->assertSee('ESTUDIO NORMAL');
    }

    public function test_el_informe_pre_operatorio_imprime_la_evaluacion_y_el_riesgo(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La evaluación pre-operatoria y su riesgo (los puntos salen de lo marcado al paciente).
        \App\Models\PreAnestesico::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'tipo' => 'A', 'resultado' => 'Apto para cirugía.', 'emergencia' => 'N',
        ]);
        \App\Models\Riesgo::create(['reg_medico' => $medico->reg_medico, 'clave' => 1, 'puntos' => 2,
            'descripcion' => 'Asma', 'sub_tipo' => 'Respiratorio']);
        \App\Models\RiesgoPaciente::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001,
            'descripcion' => 'Asma', 'sub_tipo' => 'Respiratorio', 'punto' => 2]);
        \App\Models\RiesgoPacienteOpera::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001,
            'consulta' => 1, 'puntos' => 2, 'clase' => 'II', 'conclusion' => 'Riesgo moderado.']);

        $this->conContexto($user, $medico->fresh(), $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'evaluacion-preoperatoria']))
            ->assertOk()
            ->assertSee('EVALUACIÓN PRE-OPERATORIA')
            ->assertSee('Apto para cirugía.')
            ->assertSee('2 puntos')
            ->assertSee('Riesgo moderado.');
    }

    public function test_sin_texto_no_se_ofrece_la_hoja(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin fila no hay nada que imprimir: la pantalla lo dice (O-16) en vez de imprimir una hoja vacía.
        $this->conContexto($user, $medico, $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'endoscopia-superior']))
            ->assertOk()
            ->assertSee('No hay nada que imprimir');
    }

    public function test_sin_full_texto_el_informe_usa_los_hallazgos_y_la_conclusion(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conGastro($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        EndoscopiaSuperior::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'descripcion' => 'Hallazgo de prueba.', 'conclusiones' => 'Conclusión de prueba.',
        ]);

        $this->conContexto($user, $medico->fresh(), $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'endoscopia-superior']))
            ->assertOk()
            ->assertSee('Hallazgo de prueba.')
            ->assertSee('Conclusión de prueba.');
    }
}
