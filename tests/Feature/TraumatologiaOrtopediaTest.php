<?php

namespace Tests\Feature;

use App\Actions\Consulta\GuardarLaOrtopedia;
use App\Clinica\Consulta\Traumatologia\DatosDeLaOrtopedia;
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
 * **La plantilla ortopédica de la consulta** (Traumatología, 2026-11-07): se guarda sólo lo que cambió, las casillas
 * como `1`/`0`, la fila se llavea por `nrohistoria` + `nroconsulta` y sin nada cargado no se crea.
 */
class TraumatologiaOrtopediaTest extends TestCase
{
    use DatabaseTransactions;

    private function escenario(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::firstOrCreate(
            ['slug' => 'traumatologia'],
            ['name' => 'Traumatología', 'codigo' => 'ORT', 'activo' => true],
        );
        $regMedico = 'trauma-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Traumatólogo de prueba', 'email' => 'trauma-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Traumatólogo', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Luis', 'apellidos' => 'Prueba Trauma', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'M', 'fnacimiento' => '1980-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $regMedico, 'numhistoria' => '7001']);
        Historia::create(['numhistoria' => '7001', 'reg_medico' => $regMedico, 'paciente_id' => $pacienteId, 'medico_id' => $medico->id]);

        $consulta = Consulta::create([
            'reg_medico' => $regMedico, 'numhistoria' => 7001, 'nroconsulta' => 1, 'fecha' => now()->toDateString(),
        ]);

        return [$medico, $consulta];
    }

    public function test_guarda_la_plantilla_con_las_casillas_en_uno_y_cero(): void
    {
        [, $consulta] = $this->escenario();

        $resultado = app(GuardarLaOrtopedia::class)->ejecutar($consulta, [
            'botas' => '1',
            'soporte' => 'ALTO',
            'tacon_int' => '1,5',
            'borde_int_rect' => '1',
            'suela_antirres' => '0',
            'observacion' => 'Control en un mes.',
        ]);

        $this->assertTrue($resultado['escribio']);
        $this->assertDatabaseHas('ortopedia', [
            'reg_medico' => $consulta->reg_medico, 'nrohistoria' => 7001, 'nroconsulta' => 1,
            'botas' => '1', 'borde_int_rect' => '1', 'suela_antirres' => '0', 'soporte' => 'ALTO',
        ]);
        $this->assertSame(1, DB::table('sync_changes')->where('table_name', 'ortopedia')->where('operation', 'created')->count());
    }

    public function test_sin_nada_cargado_no_crea_la_fila_y_la_lectura_la_devuelve(): void
    {
        [, $consulta] = $this->escenario();

        $this->assertFalse(app(GuardarLaOrtopedia::class)->ejecutar($consulta, ['botas' => '0', 'soporte' => ''] )['escribio']);
        $this->assertSame(0, DB::table('ortopedia')->count());

        app(GuardarLaOrtopedia::class)->ejecutar($consulta, ['soporte' => 'ALTO', 'botas' => '1']);

        $datos = new DatosDeLaOrtopedia();
        $this->assertTrue($datos->tieneDatos($consulta));
        $this->assertSame('ALTO', $datos->valores($consulta)['soporte']);
        $this->assertSame('1', $datos->valores($consulta)['botas']);
    }
}
