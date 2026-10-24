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
use Pecle\Search\Laravel\PecleSearchManager;
use Tests\TestCase;

/**
 * **El corrector de errores de tecleo en los catálogos** (PecleSearch): si lo escrito no encuentra nada, se
 * busca el término corregido y el JSON dice cuál se aplicó. Es el mismo criterio del buscador de pacientes
 * (primero lo escrito; el corrector sólo cuando no hay resultados), aplicado a los catálogos de la consulta.
 */
class BusquedaDeCatalogoCorregidaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'cat-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora', 'email' => 'cat-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function pacienteYConsulta(Medico $medico): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => '1001',
        ]);

        Historia::create([
            'numhistoria' => '1001', 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'fecha' => now()->toDateString(),
        ]);
    }

    private function contexto(Medico $medico, Specialty $especialidad): array
    {
        return ['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $especialidad->id, 'office_id' => null,
        ]];
    }

    public function test_el_buscador_del_recipe_encuentra_con_la_palabra_corregida(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);

        DB::table('vademecum')->insert([
            'reg_medico' => $medico->reg_medico, 'codemedicina' => '0001',
            'nombrecomercial' => 'Amoxicilina', 'nombregenerico' => 'Amoxicilina',
            'presentacion' => 'Suspensión 250 mg / 5 ml',
        ]);

        app(PecleSearchManager::class)->rebuild('vademecum');

        $respuesta = $this->withSession($this->contexto($medico, $gineco))
            ->actingAs($user)
            ->getJson(route('clinica.consulta.recipes.medicamentos', [$consulta->id, 'q' => 'Amoxicilna']));

        $respuesta->assertOk();
        $this->assertSame('Amoxicilina', $respuesta->json('medicamentos.0.comercial'));
        $this->assertSame('Amoxicilna', $respuesta->json('correccion.escrito'));
        $this->assertSame('Amoxicilina', $respuesta->json('correccion.aplicado'));
    }

    public function test_los_motivos_de_la_consulta_encuentran_con_la_palabra_corregida(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);

        DB::table('motivos_consulta')->insert([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '001', 'descripcion' => 'FIEBRE',
        ]);

        app(PecleSearchManager::class)->rebuild('motivos');

        $respuesta = $this->withSession($this->contexto($medico, $gineco))
            ->actingAs($user)
            ->getJson(route('clinica.consulta.motivos.buscar', [$consulta->id, 'q' => 'Fiebree']));

        $respuesta->assertOk();
        $this->assertSame('FIEBRE', $respuesta->json('motivos.0.descripcion'));
        $this->assertSame('Fiebree', $respuesta->json('correccion.escrito'));
    }

    public function test_con_literal_no_corrige_y_devuelve_lo_que_encuentra_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);

        DB::table('motivos_consulta')->insert([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '001', 'descripcion' => 'FIEBRE',
        ]);

        app(PecleSearchManager::class)->rebuild('motivos');

        $respuesta = $this->withSession($this->contexto($medico, $gineco))
            ->actingAs($user)
            ->getJson(route('clinica.consulta.motivos.buscar', [$consulta->id, 'q' => 'Fiebree', 'literal' => 1]));

        $respuesta->assertOk();
        $this->assertSame([], $respuesta->json('motivos'));
        $this->assertNull($respuesta->json('correccion'));
    }

    public function test_si_lo_escrito_encuentra_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);

        DB::table('motivos_consulta')->insert([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '001', 'descripcion' => 'FIEBRE',
        ]);

        app(PecleSearchManager::class)->rebuild('motivos');

        $respuesta = $this->withSession($this->contexto($medico, $gineco))
            ->actingAs($user)
            ->getJson(route('clinica.consulta.motivos.buscar', [$consulta->id, 'q' => 'Fiebre']));

        $respuesta->assertOk();
        $this->assertSame('FIEBRE', $respuesta->json('motivos.0.descripcion'));
        $this->assertNull($respuesta->json('correccion'));
    }
}
