<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Quién queda como médico de una cita creada desde el app (Paso 22.C): `cola.medico` es la
 * `clave` de `evolucion` (Paso 22.B), no `medicos.id`. Antes de este paso el servidor la pisaba
 * siempre con `medicos.id` — un bug invisible mientras solo hubo datos de prueba de un médico,
 * donde por casualidad `clave` e `id` coinciden.
 */
class CrearCitaMedicoTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(string $regMedico, bool $autenticar = false): Medico
    {
        $email = 'medico-ccm-' . uniqid() . '@example.com';
        $user = User::create([
            'name' => 'Doctora',
            'email' => $email,
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
            'lastname' => 'De Prueba',
            'email' => $email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    private function pacienteConHistoria(string $regMedico, Medico $medico, int $numhistoria): Paciente
    {
        $paciente = Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $regMedico,
            'numhistoria' => (string) $numhistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico' => $regMedico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);

        return $paciente;
    }

    private function crearCitaConColumnas(array $columnas): array
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => [
            [
                'table' => 'cola',
                'operation' => 'created',
                'temp_id' => -1,
                'occurred_at' => now()->toIso8601String(),
                'columns' => $columnas,
            ],
        ]])->assertOk()->json();
    }

    public function test_sin_elegir_medico_usa_la_clave_del_medico_autenticado(): void
    {
        $regMedico = 'test-ccm-' . uniqid();
        $medico = $this->medico($regMedico, autenticar: true);
        Evolucion::create(['reg_medico' => $regMedico, 'clave' => 5, 'correo_med' => $medico->email]);
        $this->pacienteConHistoria($regMedico, $medico, 1);

        $response = $this->crearCitaConColumnas([
            'fecha' => '2026-09-26', 'hora_ini' => '09:00', 'numhistoria' => 1,
        ]);

        $id = collect($response['creados'])->firstWhere('table', 'cola')['id'];
        $this->assertSame(5, Cola::find($id)->medico);
    }

    public function test_eligiendo_medico_se_respeta_la_clave_mandada(): void
    {
        // La secretaria de un consultorio compartido agenda para cualquiera de los dos médicos,
        // no solo el que tiene la sesión iniciada.
        $regMedico = 'test-ccm-' . uniqid();
        $medicoA = $this->medico($regMedico, autenticar: true);
        $medicoB = $this->medico('test-ccm-b-' . uniqid());
        MedicoRegistro::create(['medico_id' => $medicoB->id, 'reg_medico' => $regMedico]);
        Evolucion::create(['reg_medico' => $regMedico, 'clave' => 1, 'correo_med' => $medicoA->email]);
        Evolucion::create(['reg_medico' => $regMedico, 'clave' => 2, 'correo_med' => $medicoB->email]);
        $this->pacienteConHistoria($regMedico, $medicoA, 1);

        $response = $this->crearCitaConColumnas([
            'fecha' => '2026-09-26', 'hora_ini' => '09:00', 'numhistoria' => 1, 'medico' => 2,
        ]);

        $id = collect($response['creados'])->firstWhere('table', 'cola')['id'];
        $this->assertSame(2, Cola::find($id)->medico);
    }

    public function test_sin_evolucion_configurada_queda_sin_medico_en_vez_de_reventar(): void
    {
        // El caso real, según el ROADMAP: la configuración del médico casi nunca está llena.
        $regMedico = 'test-ccm-' . uniqid();
        $medico = $this->medico($regMedico, autenticar: true);
        $this->pacienteConHistoria($regMedico, $medico, 1);

        $response = $this->crearCitaConColumnas([
            'fecha' => '2026-09-26', 'hora_ini' => '09:00', 'numhistoria' => 1,
        ]);

        $id = collect($response['creados'])->firstWhere('table', 'cola')['id'];
        $this->assertNull(Cola::find($id)->medico);
    }

    public function test_offices_trae_las_sedes_de_todos_los_medicos_del_tenant(): void
    {
        $regMedico = 'test-ccm-' . uniqid();
        $medicoA = $this->medico($regMedico, autenticar: true);
        $medicoB = $this->medico('test-ccm-b-' . uniqid());
        MedicoRegistro::create(['medico_id' => $medicoB->id, 'reg_medico' => $regMedico]);
        // Reusa un centro ya sembrado: `medical_centers` exige country/state/city, y lo que
        // importa acá no es el centro en sí sino que dos sedes de médicos distintos aparezcan
        // las dos en el sync.
        $centro = MedicalCenter::firstOrFail();

        $officeA = Office::create([
            'medical_center_id' => $centro->id, 'medico_id' => $medicoA->id, 'reg_medico' => $regMedico,
            'office_number' => 'A',
        ]);
        $officeB = Office::create([
            'medical_center_id' => $centro->id, 'medico_id' => $medicoB->id, 'reg_medico' => $regMedico,
            'office_number' => 'B',
        ]);

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $ids = collect($response->json('offices'))->pluck('id');
        $this->assertTrue($ids->contains($officeA->id));
        $this->assertTrue($ids->contains($officeB->id));
    }
}
