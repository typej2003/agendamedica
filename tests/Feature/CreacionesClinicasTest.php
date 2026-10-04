<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\Recipe;
use App\Models\RecipeDetalle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Atender → consulta → récipe desde el app (ROADMAP.md Paso 18.B): llenar historia, abrir consulta y
 * guardar récipe como creaciones de `sync-app-data`, con el correlativo asignado por el servidor.
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: mismo motivo que `ConfiguracionMedicoTest`.
 */
class CreacionesClinicasTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-cc-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-cc-' . uniqid() . '@example.com',
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

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    /** Un paciente del médico, con historia `$numhistoria` o sin historia si es null. */
    private function paciente(Medico $medico, ?int $numhistoria = null): Paciente
    {
        $paciente = Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numhistoria === null ? null : (string) $numhistoria,
        ]);
        if ($numhistoria !== null) {
            Historia::create([
                'numhistoria' => (string) $numhistoria,
                'reg_medico' => $medico->reg_medico,
                'paciente_id' => $paciente->id,
                'medico_id' => $medico->id,
            ]);
        }

        return $paciente;
    }

    private function sync(array $changes)
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => $changes])->assertOk();
    }

    private function creado($response, string $tabla, int $tempId): array
    {
        return collect($response->json('creados'))
            ->first(fn ($c) => $c['table'] === $tabla && $c['temp_id'] === $tempId);
    }

    /** Llenar historia + consulta + récipe en un solo lote, como lo manda un teléfono sin conexión. */
    private function atencionCompleta(Paciente $paciente): array
    {
        return [
            ['table' => 'recipes', 'operation' => 'created', 'temp_id' => -30, 'consulta_temp_id' => -20, 'items' => [
                ['codemedicina' => 'MED001', 'cantidad' => 1, 'indicaciones' => 'Una diaria'],
                ['codemedicina' => 'MED002', 'cantidad' => 2, 'indicaciones' => 'Cada 8 horas'],
            ]],
            // Desordenados a propósito: el servidor tiene que resolver las dependencias.
            ['table' => 'consultas', 'operation' => 'created', 'temp_id' => -20, 'historia_temp_id' => -10, 'fecha' => '2026-09-24'],
            ['table' => 'historias', 'operation' => 'created', 'temp_id' => -10, 'paciente_id' => $paciente->id],
        ];
    }

    public function test_atender_sin_historia_llena_la_historia_abre_consulta_y_guarda_el_recipe(): void
    {
        $medico = $this->medico();
        $this->paciente($medico, 5);
        $this->paciente($medico, 7);
        $nuevo = $this->paciente($medico);
        $cita = Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha' => '2026-09-24',
            'hora_ini' => '09:00:00',
            'paciente_sinhistoria_id' => $nuevo->id,
        ]);

        $response = $this->sync($this->atencionCompleta($nuevo));

        // Correlativo del médico: la última (7) + 1.
        $historia = $this->creado($response, 'historias', -10);
        $this->assertSame(8, $historia['numhistoria']);
        $this->assertSame('8', MedicoPaciente::where('paciente_id', $nuevo->id)->value('numhistoria'));
        // La cita "sin historia" pasa a referenciarla.
        $this->assertSame(8, (int) $cita->fresh()->numhistoria);

        $consulta = $this->creado($response, 'consultas', -20);
        $this->assertSame(1, $consulta['nroconsulta']);

        $recipe = $this->creado($response, 'recipes', -30);
        $this->assertSame(1, $recipe['recipe']);
        $this->assertCount(2, $recipe['ids']);
        $filas = Recipe::whereIn('id', $recipe['ids'])->orderBy('orden')->get();
        $this->assertSame(['MED001', 'MED002'], $filas->pluck('codemedicina')->all());
        $this->assertSame([8, 8], $filas->pluck('nrohistoria')->all());
        $this->assertSame(1, RecipeDetalle::where('nrohistoria', 8)->where('reg_medico', $medico->reg_medico)->count());

        // Y la consulta y el récipe bajan en la misma respuesta (el pivote se relee).
        $this->assertTrue(collect($response->json('consultas'))->contains('id', $consulta['id']));
        $this->assertTrue(collect($response->json('recipes'))->contains('id', $recipe['ids'][0]));
    }

    public function test_reintentar_el_mismo_lote_no_numera_de_nuevo(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente($medico);

        $primera = $this->sync($this->atencionCompleta($paciente));
        $segunda = $this->sync($this->atencionCompleta($paciente));

        foreach ([['historias', -10], ['consultas', -20], ['recipes', -30]] as [$tabla, $temp]) {
            $this->assertSame($this->creado($primera, $tabla, $temp), $this->creado($segunda, $tabla, $temp));
        }
        $this->assertSame(1, Historia::where('paciente_id', $paciente->id)->count());
        $this->assertSame(2, Recipe::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_recipe_de_un_lote_posterior_encuentra_la_consulta_por_su_id_temporal(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente($medico, 3);

        $this->sync([
            ['table' => 'consultas', 'operation' => 'created', 'temp_id' => -20, 'numhistoria' => 3],
        ]);
        $response = $this->sync([
            ['table' => 'recipes', 'operation' => 'created', 'temp_id' => -30, 'consulta_temp_id' => -20,
                'items' => [['codemedicina' => 'MED005']]],
        ]);

        $this->assertNotNull($this->creado($response, 'recipes', -30));
        $this->assertSame([], $response->json('rechazados'));
    }

    public function test_la_consulta_sigue_el_correlativo_de_la_historia_y_el_recipe_el_de_la_consulta(): void
    {
        $medico = $this->medico();
        $this->paciente($medico, 4);
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 4, 'nroconsulta' => 1]);
        $consulta2 = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 4, 'nroconsulta' => 2]);
        // Récipe viejo del legado: `recipe` en NULL cuenta como el 1.
        Recipe::create([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 4, 'nroconsulta' => 2,
            'codemedicina' => 'MED001', 'recipe' => null,
        ]);

        $response = $this->sync([
            ['table' => 'consultas', 'operation' => 'created', 'temp_id' => -20, 'numhistoria' => 4],
            ['table' => 'recipes', 'operation' => 'created', 'temp_id' => -30, 'consulta_id' => $consulta2->id,
                'items' => [['codemedicina' => 'MED003']]],
        ]);

        $this->assertSame(3, $this->creado($response, 'consultas', -20)['nroconsulta']);
        $this->assertSame(2, $this->creado($response, 'recipes', -30)['recipe']);
    }

    public function test_un_paciente_que_ya_tiene_historia_devuelve_la_suya(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente($medico, 12);

        $response = $this->sync([
            ['table' => 'historias', 'operation' => 'created', 'temp_id' => -10, 'paciente_id' => $paciente->id],
        ]);

        $this->assertSame(12, $this->creado($response, 'historias', -10)['numhistoria']);
        $this->assertSame(1, Historia::where('paciente_id', $paciente->id)->count());
    }

    public function test_no_se_puede_escribir_en_historias_ni_consultas_de_otro_medico(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajeno = $this->paciente($otro, 1);
        $consultaAjena = Consulta::create(['reg_medico' => $otro->reg_medico, 'numhistoria' => 1, 'nroconsulta' => 1]);

        $this->medico();

        $response = $this->sync([
            ['table' => 'historias', 'operation' => 'created', 'temp_id' => -10, 'paciente_id' => $ajeno->id],
            ['table' => 'consultas', 'operation' => 'created', 'temp_id' => -20, 'numhistoria' => 1],
            ['table' => 'recipes', 'operation' => 'created', 'temp_id' => -30, 'consulta_id' => $consultaAjena->id,
                'items' => [['codemedicina' => 'MED001']]],
        ]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(3, $response->json('rechazados'));
        $this->assertSame(0, Recipe::where('nroconsulta', 1)->where('reg_medico', $otro->reg_medico)->count());
    }

    public function test_dos_medicos_pueden_tener_la_misma_historia_y_no_ven_las_consultas_del_otro(): void
    {
        $otro = $this->medico(autenticar: false);
        $this->paciente($otro, 1);
        $ajena = Consulta::create(['reg_medico' => $otro->reg_medico, 'numhistoria' => 1, 'nroconsulta' => 1]);

        $medico = $this->medico();
        $this->paciente($medico, 1);
        $propia = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1, 'nroconsulta' => 1]);

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $ids = collect($response->json('consultas'))->pluck('id');
        $this->assertTrue($ids->contains($propia->id));
        $this->assertFalse($ids->contains($ajena->id));
    }

    public function test_un_recipe_sin_medicamentos_validos_se_rechaza_sin_trabar_el_lote(): void
    {
        $medico = $this->medico();
        $this->paciente($medico, 2);
        $consulta = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 2, 'nroconsulta' => 1]);

        $response = $this->sync([
            ['table' => 'recipes', 'operation' => 'created', 'temp_id' => -30, 'consulta_id' => $consulta->id, 'items' => []],
            ['table' => 'recipes', 'operation' => 'created', 'temp_id' => -31, 'consulta_id' => $consulta->id,
                'items' => [['codemedicina' => 'CODIGO-DEMASIADO-LARGO']]],
            ['table' => 'consultas', 'operation' => 'created', 'temp_id' => -20, 'numhistoria' => 2, 'fecha' => '24/09/2026'],
        ]);

        $this->assertCount(2, $response->json('rechazados'));
        // La fecha mal formada no rechaza la consulta: se usa la de hoy.
        $this->assertSame(now()->toDateString(), Consulta::find($this->creado($response, 'consultas', -20)['id'])->fecha->toDateString());
    }
}
