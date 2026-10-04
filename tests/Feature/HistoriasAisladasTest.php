<?php

namespace Tests\Feature;

use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cada médico recibe **solo sus** historias en `sync-app-data` (ROADMAP.md Paso 18.B2.D, hallazgo del
 * emulador). El número de historia es por médico desde el Paso 18.B1 —cada instalación del legado
 * arranca en 1—, así que filtrar por número dejaba pasar las historias de cualquier otro médico con el
 * mismo número: el teléfono las mezclaba con las suyas (agenda, centro, nombre en la consulta).
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: mismo motivo que `CreacionesClinicasTest`.
 */
class HistoriasAisladasTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true, array $registrosExtra = []): Medico
    {
        $regMedico = 'test-ha-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-ha-' . uniqid() . '@example.com',
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
        foreach ($registrosExtra as $extra) {
            MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $extra]);
        }

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    private function paciente(string $nombres = 'Paciente'): Paciente
    {
        return Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999) . random_int(10, 99),
            'nombres' => $nombres,
            'apellidos' => 'De Prueba',
        ]);
    }

    /** Un paciente del médico con su historia `$numhistoria` (pivote + historia, como la deja la carga). */
    private function historia(Medico $medico, Paciente $paciente, int $numhistoria, ?string $regMedico = null): Historia
    {
        $reg = $regMedico ?? $medico->reg_medico;
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $reg,
            'numhistoria' => (string) $numhistoria,
        ]);

        return Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico' => $reg,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);
    }

    private function sync(array $body = [])
    {
        return $this->postJson('/api/app/sync-app-data', $body)->assertOk();
    }

    private function ids($response): array
    {
        return collect($response->json('historias'))->pluck('id')->sort()->values()->all();
    }

    public function test_dos_medicos_con_el_mismo_numero_de_historia_reciben_cada_uno_la_suya(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajena = $this->historia($otro, $this->paciente('Ajena'), 1);

        $medico = $this->medico();
        $propia = $this->historia($medico, $this->paciente('Propia'), 1);

        $this->assertSame([$propia->id], $this->ids($this->sync()));
        $this->assertNotContains($ajena->id, $this->ids($this->sync()));
    }

    public function test_un_paciente_compartido_trae_solo_la_historia_de_este_medico(): void
    {
        $compartido = $this->paciente('Compartida');
        $otro = $this->medico(autenticar: false);
        $this->historia($otro, $compartido, 5);
        $ajena = Historia::where('reg_medico', $otro->reg_medico)->first();

        $medico = $this->medico();
        // El mismo paciente, con otro número en este médico, y otro con el número que el otro usa.
        $propia = $this->historia($medico, $compartido, 9);
        $propiaCincoDeOtroPaciente = $this->historia($medico, $this->paciente('Otra'), 5);

        $ids = $this->ids($this->sync());

        $this->assertSame([$propia->id, $propiaCincoDeOtroPaciente->id], $ids);
        $this->assertNotContains($ajena->id, $ids);
        // Y por cada paciente llega una sola historia: el teléfono arma paciente → número con ellas.
        $respuesta = collect($this->sync()->json('historias'));
        $this->assertCount(1, $respuesta->where('paciente_id', $compartido->id));
        $this->assertSame('9', $respuesta->firstWhere('paciente_id', $compartido->id)['numhistoria']);
    }

    public function test_un_medico_con_dos_registros_recibe_las_historias_de_los_dos(): void
    {
        $segundo = 'test-ha-extra-' . uniqid();
        $medico = $this->medico(registrosExtra: [$segundo]);
        $deLaPrimera = $this->historia($medico, $this->paciente(), 1);
        $deLaSegunda = $this->historia($medico, $this->paciente(), 1, $segundo);

        // Otro médico con ese mismo número, que no tiene por qué llegar.
        $otro = $this->medico(autenticar: false);
        $ajena = $this->historia($otro, $this->paciente(), 1);

        $ids = $this->ids($this->sync());

        $this->assertSame([$deLaPrimera->id, $deLaSegunda->id], $ids);
        $this->assertNotContains($ajena->id, $ids);
    }

    public function test_una_historia_sin_dueno_no_llega_aunque_su_numero_este_en_el_pivote(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente();
        $this->historia($medico, $paciente, 3);
        // Una fila suelta (sin `reg_medico` ni `medico_id`), como las de los primeros intentos de carga,
        // con el número de una historia de este médico. No es de nadie: no se le manda a nadie.
        $huerfana = Historia::create(['numhistoria' => '3', 'paciente_id' => $this->paciente()->id]);

        $this->assertNotContains($huerfana->id, $this->ids($this->sync()));
    }

    public function test_el_delta_trae_solo_las_propias_que_cambiaron(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajena = $this->historia($otro, $this->paciente(), 1);

        $medico = $this->medico();
        $vieja = $this->historia($medico, $this->paciente(), 1);
        $vieja->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        $nueva = $this->historia($medico, $this->paciente(), 2);
        $ajena->touch();

        $response = $this->sync(['since' => now()->subDay()->toIso8601String()]);

        $this->assertSame([$nueva->id], $this->ids($response));
    }

    public function test_una_historia_creada_desde_el_app_llega_en_la_misma_respuesta_sin_las_ajenas(): void
    {
        $otro = $this->medico(autenticar: false);
        $this->historia($otro, $this->paciente(), 1);

        $medico = $this->medico();
        $paciente = $this->paciente();
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => null,
        ]);

        $response = $this->sync(['changes' => [
            ['table' => 'historias', 'operation' => 'created', 'temp_id' => -10, 'paciente_id' => $paciente->id],
        ]]);

        $creada = collect($response->json('creados'))->firstWhere('table', 'historias');
        $this->assertSame(1, $creada['numhistoria'], 'su correlativo es el del médico, no el del otro');
        $this->assertSame([$creada['id']], $this->ids($response));
    }
}
