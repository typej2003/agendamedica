<?php

namespace Tests\Feature;

use App\Models\ConstanciaObs;
use App\Models\Consulta;
use App\Models\DietaPaciente;
use App\Models\Hospitalizacion;
use App\Models\Informe;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Referencia;
use App\Models\ReposoPaciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los dos primeros documentos que el teléfono crea: **constancia** y **reposo**. Viajan como creaciones
 * clínicas y el servidor los escribe con las mismas Actions de la web.
 */
class SyncDocumentosCreacionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): Medico
    {
        $regMedico = 'test-doc' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-doc' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);
        Sanctum::actingAs($user, ['*'], 'api');

        return $medico;
    }

    private function consulta(Medico $medico): Consulta
    {
        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => 910001,
            'nroconsulta' => 1,
            'fecha' => '2026-10-01',
        ]);
    }

    private function sync(array $changes)
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => $changes])->assertOk();
    }

    public function test_crea_la_constancia_con_su_observacion(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'constancia_obs',
            'operation' => 'created',
            'temp_id' => -70,
            'consulta_id' => $consulta->id,
            'columns' => ['observacion01' => 'La paciente se retira en buen estado.'],
        ]]);

        $fila = ConstanciaObs::where('numhistoria', 910001)->where('numconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('La paciente se retira en buen estado.', $fila->observacion01);
        $creado = collect($response->json('creados'))->firstWhere('table', 'constancia_obs');
        $this->assertNotNull($creado);
        $this->assertSame($fila->id, $creado['id']);
    }

    public function test_crea_el_reposo_con_dias_desde_y_justificacion(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sync([[
            'table' => 'reposo_paciente',
            'operation' => 'created',
            'temp_id' => -71,
            'consulta_id' => $consulta->id,
            'columns' => [
                'numdias' => 5,
                'fdesde' => '2026-10-01',
                'obser_reposo' => 'Reposo absoluto.',
            ],
        ]]);

        $fila = ReposoPaciente::where('nrohistoria', 910001)->where('nroconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame(5, $fila->numdias);
        $this->assertSame('2026-10-01', $fila->fdesde->format('Y-m-d'));
        $this->assertSame('Reposo absoluto.', $fila->obser_reposo);
    }

    public function test_un_reposo_de_un_dia_sin_justificacion_se_rechaza(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'reposo_paciente',
            'operation' => 'created',
            'temp_id' => -72,
            'consulta_id' => $consulta->id,
            'columns' => ['numdias' => 1, 'fdesde' => '2026-10-01'],
        ]]);

        $this->assertNotEmpty($response->json('rechazados'));
        $this->assertNull(ReposoPaciente::where('nrohistoria', 910001)->first());
    }

    public function test_crea_la_referencia_a_un_medico_de_la_libreta(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        DB::table('doctores')->insert([
            'reg_medico' => $medico->reg_medico,
            'cedula' => 12345678,
            'nombres' => 'Carlos',
            'apellidos' => 'Pérez',
            'codeespecial' => '002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(\App\Models\Doctor::where('reg_medico', $medico->reg_medico)
            ->where('cedula', 12345678)->exists(), 'El médico de prueba quedó en la libreta');

        $response = $this->sync([[
            'table' => 'referencia',
            'operation' => 'created',
            'temp_id' => -73,
            'consulta_id' => $consulta->id,
            'ceduladoctor' => 12345678,
            'columns' => ['referencia' => 'Evaluación por cardiología.'],
        ]]);
        $this->assertEmpty($response->json('rechazados'), json_encode($response->json('rechazados')));

        $fila = Referencia::where('nrohistoria', 910001)->where('ceduladoctor', 12345678)->first();
        $this->assertNotNull($fila);
        $this->assertSame('Evaluación por cardiología.', $fila->referencia);
    }

    public function test_crea_el_informe_con_texto(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sync([[
            'table' => 'informe',
            'operation' => 'created',
            'temp_id' => -74,
            'consulta_id' => $consulta->id,
            'columns' => [
                'para' => 'Dr. Pérez',
                'descripcion' => 'Informe médico completo.',
                'fe_cha' => '2026-10-01',
            ],
        ]]);

        $fila = Informe::where('nrohistoria', 910001)->where('nroconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('Informe médico completo.', $fila->descripcion);
        $this->assertSame('Dr. Pérez', $fila->para);
    }

    public function test_un_informe_sin_texto_se_rechaza(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'informe',
            'operation' => 'created',
            'temp_id' => -75,
            'consulta_id' => $consulta->id,
            'columns' => ['para' => 'Dr. Pérez', 'descripcion' => '.'],
        ]]);

        $this->assertNotEmpty($response->json('rechazados'));
        $this->assertNull(Informe::where('nrohistoria', 910001)->first());
    }

    public function test_crea_la_orden_de_hospitalizacion(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sync([[
            'table' => 'hospitalizacion',
            'operation' => 'created',
            'temp_id' => -76,
            'consulta_id' => $consulta->id,
            'columns' => [
                'hospital' => 'Clínica Norte',
                'diagprev' => 'Colelitiasis.',
                'dieta' => 'Ingreso por guardia.',
            ],
        ]]);

        $fila = Hospitalizacion::where('nrohistoria', 910001)->where('nroconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('Clínica Norte', $fila->hospital);
        $this->assertSame('Colelitiasis.', $fila->diagprev);
    }

    public function test_crea_la_dieta_con_titulo_y_texto(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sync([[
            'table' => 'dieta_paciente',
            'operation' => 'created',
            'temp_id' => -77,
            'consulta_id' => $consulta->id,
            'columns' => [
                'dieta' => 'Dieta blanda',
                'descripcion' => 'Fraccionada, sin frituras.',
            ],
        ]]);

        $fila = DietaPaciente::where('histroria', 910001)->where('consulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('Dieta blanda', $fila->dieta);
        $this->assertSame('Fraccionada, sin frituras.', $fila->descripcion);
    }
}
