<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El comando que lista las consultas con el número de historia de otro consultorio** (H-2, 2026-11-02).
 *
 * Es el diagnóstico previo al despliegue de la corrección: esas consultas dejan de resolver paciente, así que hay
 * que saber cuántas son. Sólo lectura.
 */
class HistoriasCruzadasCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:Medico, 1:string} */
    private function medicoConDosConsultorios(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'cruzadas-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => $regMedico . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        $otro = $regMedico . '-b';
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $otro]);

        return [$medico, $otro];
    }

    private function paciente(string $nombres): int
    {
        return DB::table('pacientes')->insertGetId([
            'nombres' => $nombres, 'apellidos' => 'De Prueba', 'cedula' => (string) random_int(1000000, 9999999),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function consulta(Medico $medico, int $numero): int
    {
        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numero,
            'nroconsulta' => 1,
            'fecha' => now()->toDateString(),
        ])->id;
    }

    public function test_lista_la_consulta_cuyo_numero_es_la_historia_de_otro_consultorio(): void
    {
        [$medico, $otro] = $this->medicoConDosConsultorios();

        // El 21 es la historia registrada del otro consultorio del mismo médico.
        $ajeno = $this->paciente('Ajena');
        Historia::create([
            'numhistoria' => '21', 'reg_medico' => $otro, 'paciente_id' => $ajeno, 'medico_id' => $medico->id,
        ]);

        $cruzada = $this->consulta($medico, 21);

        // Y esta otra está sana: su número sí es suyo.
        $propia = $this->paciente('Propia');
        Historia::create([
            'numhistoria' => '22', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $propia, 'medico_id' => $medico->id,
        ]);
        $this->consulta($medico, 22);

        $this->artisan('clinica:historias-cruzadas')
            ->expectsOutputToContain((string) $cruzada)
            ->doesntExpectOutputToContain('No hay consultas')
            ->assertExitCode(0);
    }

    public function test_sin_casos_lo_dice_y_no_inventa_filas(): void
    {
        [$medico] = $this->medicoConDosConsultorios();

        $propia = $this->paciente('Propia');
        Historia::create([
            'numhistoria' => '30', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $propia, 'medico_id' => $medico->id,
        ]);
        $this->consulta($medico, 30);

        $this->artisan('clinica:historias-cruzadas')
            ->expectsOutputToContain('No hay consultas')
            ->assertExitCode(0);
    }
}
