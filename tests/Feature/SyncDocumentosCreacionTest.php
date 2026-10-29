<?php

namespace Tests\Feature;

use App\Models\ConstanciaObs;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\ReposoPaciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
