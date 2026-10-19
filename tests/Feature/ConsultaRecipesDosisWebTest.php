<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La dosis del niño en el buscador del récipe** (2026-10-19): el JSON de `/recipes/medicamentos` trae, además del
 * medicamento, la **indicación ya calculada** con el peso de la consulta cuando el vademécum dice que es por kilo.
 *
 * La fórmula y sus casos están en `Unit/Clinica/DosisPediatricaTest`; acá se fija el cableado: el peso que se usa es
 * el de **la consulta**, el tope de 30 kg llega al texto, y un medicamento sin dosis por kilo no trae sugerencia.
 */
class ConsultaRecipesDosisWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'dosis-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora', 'email' => 'dosis-' . substr(uniqid(), -6) . '@example.com',
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

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico, ?float $peso = null): array
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
            'fecha' => now()->toDateString(), 'peso' => $peso,
        ]);

        return [$pacienteId, $consulta];
    }

    private function medicamento(Medico $medico, array $datos = []): void
    {
        DB::table('vademecum')->insert($datos + [
            'reg_medico' => $medico->reg_medico,
            'codemedicina' => '0001',
            'nombrecomercial' => 'Amoxicilina',
            'nombregenerico' => 'Amoxicilina',
            'presentacion' => 'Suspensión 250 mg / 5 ml',
            'uso' => 'Según indicación del médico.',
        ]);
    }

    public function test_el_buscador_trae_la_indicacion_calculada_con_el_peso(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->medicamento($medico, ['dosificacion' => 'SI', 'dosis' => 20, 'cada' => 8, 'durante' => 7,
            'pvc' => 250, 'pvs' => 5, 'concentracion' => 0]);
        [, $consulta] = $this->pacienteYConsulta($medico, 12.0);

        $respuesta = $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $pediatria->id, 'office_id' => null,
        ]])->actingAs($user)->getJson(route('clinica.consulta.recipes.medicamentos', [$consulta->id, 'q' => 'amoxi']));

        $respuesta->assertOk();

        $medicamento = $respuesta->json('medicamentos.0');

        // 20 mg/kg × 12 kg / 8 h = 30 mg; el envase tiene 250 mg en 5 ml → 5 × 30 / 250 = 0.6 ml.
        $this->assertSame('DAR 0.6 ML CADA 3 HORAS POR 7 DÍAS', $medicamento['indicacionSugerida']);
        $this->assertEqualsWithDelta(0.6, $medicamento['dosisCalculada']['ml'], 0.001);
    }

    public function test_sin_peso_en_la_consulta_no_hay_sugerencia(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->medicamento($medico, ['dosificacion' => 'SI', 'dosis' => 20, 'cada' => 8, 'durante' => 7]);
        [, $consulta] = $this->pacienteYConsulta($medico, null);

        $respuesta = $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $pediatria->id, 'office_id' => null,
        ]])->actingAs($user)->getJson(route('clinica.consulta.recipes.medicamentos', [$consulta->id, 'q' => 'amoxi']));

        $respuesta->assertOk();
        $this->assertNull($respuesta->json('medicamentos.0.indicacionSugerida'));
    }

    public function test_un_medicamento_que_no_es_por_kilo_no_trae_sugerencia(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->medicamento($medico, ['dosificacion' => 'NO', 'dosis' => 20, 'cada' => 8, 'durante' => 7]);
        [, $consulta] = $this->pacienteYConsulta($medico, 12.0);

        $respuesta = $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $pediatria->id, 'office_id' => null,
        ]])->actingAs($user)->getJson(route('clinica.consulta.recipes.medicamentos', [$consulta->id, 'q' => 'amoxi']));

        $respuesta->assertOk();
        $this->assertNull($respuesta->json('medicamentos.0.indicacionSugerida'));
    }
}
