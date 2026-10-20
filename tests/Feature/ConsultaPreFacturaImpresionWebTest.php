<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PreFacturaConsulta;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El informe impreso de la pre-factura** (F9): un renglón por concepto (cantidad × monto de la fila = subtotal) y el
 * total. Se ofrece sólo si la consulta tiene algún concepto; la lista de la cita lleva la cuenta y no los montos.
 */
class ConsultaPreFacturaImpresionWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'prefac-imp-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'prefac-imp-' . substr(uniqid(), -6) . '@example.com',
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

    private function concepto(Medico $medico, int $registro, string $codigo, string $descripcion, float $cantidad, float $monto): void
    {
        PreFacturaConsulta::create([
            'reg_medico' => $medico->reg_medico, 'id_registro' => $registro, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'codigo' => $codigo, 'descripcion' => $descripcion, 'cantidad' => $cantidad, 'monto' => $monto,
        ]);
    }

    public function test_la_hoja_imprime_los_conceptos_con_sus_subtotales_y_el_total(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->concepto($medico, 1, '001', 'CONSULTA DE CONTROL', 2, 50);
        $this->concepto($medico, 2, '002', 'MATERIAL', 1, 30.5);

        $this->conContexto($user, $medico, $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'prefactura']))
            ->assertOk()
            ->assertSee('PRE-FACTURA')
            ->assertSee('1. CONSULTA DE CONTROL — 2 × 50,00 = 100,00')
            ->assertSee('2. MATERIAL — 1 × 30,50 = 30,50')
            ->assertSee('130,50');
    }

    public function test_sin_conceptos_no_se_ofrece_la_hoja(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'prefactura']))
            ->assertOk()
            ->assertSee('No hay nada que imprimir');
    }

    public function test_el_monto_de_la_fila_manda_sobre_el_del_catalogo(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        DB::table('motivo_factura')->insert(['reg_medico' => $medico->reg_medico, 'codigo' => '001', 'descripcion' => 'CONSULTA', 'monto' => 999]);
        $this->concepto($medico, 1, '001', 'CONSULTA', 1, 45);

        $this->conContexto($user, $medico, $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'prefactura']))
            ->assertOk()->assertSee('1 × 45,00 = 45,00')->assertDontSee('999,00');
    }
}
