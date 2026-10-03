<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoConsulta;
use App\Models\MotivoConsultaPaciente;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Motivos de consulta desde el app (ROADMAP.md Paso 18.B2): el catálogo y los de cada consulta bajan
 * en `sync-app-data`, y el app puede crear un motivo nuevo, agregar uno a la consulta y quitarlo.
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: mismo motivo que `CreacionesClinicasTest`.
 */
class MotivosConsultaTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-mc-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-mc-' . uniqid() . '@example.com',
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

    /** Un paciente del médico con historia `$numhistoria` y una consulta (la 1). */
    private function consulta(Medico $medico, int $numhistoria = 1): Consulta
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
            'numhistoria' => (string) $numhistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => 1,
        ]);
    }

    private function motivo(Medico $medico, string $codigo, string $descripcion): MotivoConsulta
    {
        return MotivoConsulta::create([
            'reg_medico' => $medico->reg_medico,
            'codemotivo' => $codigo,
            'descripcion' => $descripcion,
        ]);
    }

    private function sync(array $changes, array $extra = [])
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => $changes] + $extra)->assertOk();
    }

    private function creado($response, string $tabla, int $tempId): ?array
    {
        return collect($response->json('creados'))
            ->first(fn ($c) => $c['table'] === $tabla && $c['temp_id'] === $tempId);
    }

    public function test_el_catalogo_y_los_motivos_de_la_consulta_bajan_solo_los_del_medico(): void
    {
        $otro = $this->medico(autenticar: false);
        $this->consulta($otro, 1);
        $ajenoCatalogo = $this->motivo($otro, '0001', 'AJENO');
        $ajenoLigado = MotivoConsultaPaciente::create([
            'reg_medico' => $otro->reg_medico, 'codemotivo' => '0001', 'nrohistoria' => 1, 'nroconsulta' => 1,
        ]);

        $medico = $this->medico();
        $consulta = $this->consulta($medico, 1);
        $propioCatalogo = $this->motivo($medico, '0001', 'DOLOR PELVICO');
        $propioLigado = MotivoConsultaPaciente::create([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '0001',
            'nrohistoria' => $consulta->numhistoria, 'nroconsulta' => $consulta->nroconsulta,
        ]);

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $catalogo = collect($response->json('motivos_consulta'))->pluck('id');
        $this->assertTrue($catalogo->contains($propioCatalogo->id));
        $this->assertFalse($catalogo->contains($ajenoCatalogo->id));

        $ligados = collect($response->json('motivo_consulta_paciente'))->pluck('id');
        $this->assertTrue($ligados->contains($propioLigado->id));
        $this->assertFalse($ligados->contains($ajenoLigado->id));
    }

    public function test_el_delta_solo_trae_lo_que_cambio_despues_del_since(): void
    {
        $medico = $this->medico();
        $this->consulta($medico);
        $viejo = $this->motivo($medico, '0001', 'VIEJO');
        $viejo->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        $nuevo = $this->motivo($medico, '0002', 'NUEVO');

        $response = $this->postJson('/api/app/sync-app-data', ['since' => now()->subDay()->toIso8601String()])->assertOk();

        $ids = collect($response->json('motivos_consulta'))->pluck('id');
        $this->assertTrue($ids->contains($nuevo->id));
        $this->assertFalse($ids->contains($viejo->id));
    }

    public function test_agregar_un_motivo_existente_a_una_consulta_y_que_baje_en_la_misma_respuesta(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico, 4);
        $this->motivo($medico, '0007', 'SANGRADO');

        $response = $this->sync([
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -1,
                'consulta_id' => $consulta->id, 'codemotivo' => '0007'],
        ]);

        $creado = $this->creado($response, 'motivo_consulta_paciente', -1);
        $fila = MotivoConsultaPaciente::findOrFail($creado['id']);
        $this->assertSame('0007', $fila->codemotivo);
        $this->assertSame(4, $fila->nrohistoria);
        $this->assertSame(1, $fila->nroconsulta);
        $this->assertSame($medico->reg_medico, $fila->reg_medico);
        $this->assertSame([], $response->json('rechazados'));
        $this->assertTrue(collect($response->json('motivo_consulta_paciente'))->contains('id', $fila->id));
    }

    public function test_el_mismo_motivo_dos_veces_en_la_misma_consulta_deja_una_sola_fila(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->motivo($medico, '0007', 'SANGRADO');
        $cambio = fn (int $temp) => ['table' => 'motivo_consulta_paciente', 'operation' => 'created',
            'temp_id' => $temp, 'consulta_id' => $consulta->id, 'codemotivo' => '0007'];

        $primera = $this->sync([$cambio(-1)]);
        $reintento = $this->sync([$cambio(-1)]);
        $otraVez = $this->sync([$cambio(-2)]);

        $this->assertSame($this->creado($primera, 'motivo_consulta_paciente', -1), $this->creado($reintento, 'motivo_consulta_paciente', -1));
        $this->assertSame(
            $this->creado($primera, 'motivo_consulta_paciente', -1)['id'],
            $this->creado($otraVez, 'motivo_consulta_paciente', -2)['id'],
        );
        $this->assertSame(1, MotivoConsultaPaciente::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_motivo_nuevo_recibe_el_siguiente_codigo_en_mayusculas(): void
    {
        $medico = $this->medico();
        $this->motivo($medico, '0001', 'UNO');
        $this->motivo($medico, '0007', 'SIETE');

        $response = $this->sync([
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -1,
                'columns' => ['descripcion' => '  dolor   de cabeza ']],
        ]);

        $creado = $this->creado($response, 'motivos_consulta', -1);
        $this->assertSame('0008', $creado['codemotivo']);
        $this->assertSame('DOLOR DE CABEZA', $creado['descripcion']);
        $this->assertSame($medico->reg_medico, MotivoConsulta::find($creado['id'])->reg_medico);
        $this->assertTrue(collect($response->json('motivos_consulta'))->contains('id', $creado['id']));
    }

    public function test_el_primer_motivo_de_un_catalogo_vacio_es_el_0001(): void
    {
        $this->medico();

        $response = $this->sync([
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -1, 'columns' => ['descripcion' => 'Control']],
        ]);

        $this->assertSame('0001', $this->creado($response, 'motivos_consulta', -1)['codemotivo']);
    }

    public function test_un_motivo_con_la_misma_descripcion_no_se_duplica_en_el_catalogo(): void
    {
        $medico = $this->medico();
        $existente = $this->motivo($medico, '0003', 'DOLOR PELVICO');

        $response = $this->sync([
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -1, 'columns' => ['descripcion' => 'dolor pelvico']],
        ]);

        $this->assertSame($existente->id, $this->creado($response, 'motivos_consulta', -1)['id']);
        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_dos_telefonos_que_crean_el_mismo_motivo_reciben_el_mismo_id_y_codigo(): void
    {
        $medico = $this->medico();

        // Cada teléfono manda su propio `temp_id` y escribe el nombre a su manera.
        $primero = $this->sync([
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => 111, 'columns' => ['descripcion' => 'Dolor pélvico']],
        ]);
        $segundo = $this->sync([
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => 987, 'columns' => ['descripcion' => ' DOLOR  PÉLVICO ']],
        ]);

        $a = $this->creado($primero, 'motivos_consulta', 111);
        $b = $this->creado($segundo, 'motivos_consulta', 987);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame($a['codemotivo'], $b['codemotivo']);
        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_reintentar_la_creacion_de_un_motivo_no_numera_de_nuevo(): void
    {
        $medico = $this->medico();
        $cambio = [['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -1, 'columns' => ['descripcion' => 'Nuevo']]];

        $primera = $this->sync($cambio);
        $segunda = $this->sync($cambio);

        $this->assertSame($this->creado($primera, 'motivos_consulta', -1), $this->creado($segunda, 'motivos_consulta', -1));
        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_motivo_nuevo_consulta_nueva_y_vinculo_en_un_lote_desordenado(): void
    {
        $medico = $this->medico();
        $this->consulta($medico, 9);

        $response = $this->sync([
            // Desordenados a propósito: el servidor tiene que resolver las dependencias.
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -3,
                'consulta_temp_id' => -2, 'motivo_temp_id' => -1],
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -1, 'columns' => ['descripcion' => 'Prurito']],
            ['table' => 'consultas', 'operation' => 'created', 'temp_id' => -2, 'numhistoria' => 9],
        ]);

        $this->assertSame([], $response->json('rechazados'));
        $consulta = $this->creado($response, 'consultas', -2);
        $motivo = $this->creado($response, 'motivos_consulta', -1);
        $fila = MotivoConsultaPaciente::findOrFail($this->creado($response, 'motivo_consulta_paciente', -3)['id']);
        $this->assertSame($motivo['codemotivo'], $fila->codemotivo);
        $this->assertSame(9, $fila->nrohistoria);
        $this->assertSame($consulta['nroconsulta'], $fila->nroconsulta);
    }

    public function test_un_motivo_de_un_lote_posterior_encuentra_el_motivo_y_la_consulta_por_su_id_temporal(): void
    {
        $medico = $this->medico();
        $this->consulta($medico, 2);

        $this->sync([
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -1, 'columns' => ['descripcion' => 'Prurito']],
            ['table' => 'consultas', 'operation' => 'created', 'temp_id' => -2, 'numhistoria' => 2],
        ]);
        $response = $this->sync([
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -3,
                'consulta_temp_id' => -2, 'motivo_temp_id' => -1],
        ]);

        $this->assertNotNull($this->creado($response, 'motivo_consulta_paciente', -3));
        $this->assertSame([], $response->json('rechazados'));
    }

    public function test_motivos_invalidos_se_rechazan_sin_trabar_el_lote(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->motivo($medico, '0001', 'VALIDO');

        $response = $this->sync([
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -1, 'columns' => ['descripcion' => '   ']],
            ['table' => 'motivos_consulta', 'operation' => 'created', 'temp_id' => -2, 'columns' => ['descripcion' => str_repeat('A', 41)]],
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -3,
                'consulta_id' => $consulta->id, 'codemotivo' => '9999'],
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -4,
                'consulta_id' => $consulta->id],
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -5,
                'consulta_id' => $consulta->id, 'codemotivo' => '0001'],
        ]);

        $this->assertCount(4, $response->json('rechazados'));
        $this->assertNotNull($this->creado($response, 'motivo_consulta_paciente', -5));
        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_no_se_puede_usar_la_consulta_ni_el_catalogo_de_otro_medico(): void
    {
        $otro = $this->medico(autenticar: false);
        $consultaAjena = $this->consulta($otro, 1);
        $this->motivo($otro, '0001', 'AJENO');

        $medico = $this->medico();
        $propia = $this->consulta($medico, 1);

        $response = $this->sync([
            // Consulta ajena con un motivo ajeno.
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -1,
                'consulta_id' => $consultaAjena->id, 'codemotivo' => '0001'],
            // Consulta propia con un código que solo existe en el catálogo de otro.
            ['table' => 'motivo_consulta_paciente', 'operation' => 'created', 'temp_id' => -2,
                'consulta_id' => $propia->id, 'codemotivo' => '0001'],
        ]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(2, $response->json('rechazados'));
        $this->assertSame(0, MotivoConsultaPaciente::whereIn('reg_medico', [$medico->reg_medico, $otro->reg_medico])->count());
    }

    public function test_quitar_un_motivo_de_la_consulta_no_borra_el_catalogo_y_avisa_a_los_demas(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $catalogo = $this->motivo($medico, '0001', 'DOLOR');
        $fila = MotivoConsultaPaciente::create([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '0001',
            'nrohistoria' => $consulta->numhistoria, 'nroconsulta' => $consulta->nroconsulta,
        ]);
        $antes = now()->subMinute()->toIso8601String();

        $this->sync([['table' => 'motivo_consulta_paciente', 'operation' => 'deleted', 'record_id' => $fila->id]]);

        $this->assertNull(MotivoConsultaPaciente::find($fila->id));
        $this->assertNotNull(MotivoConsulta::find($catalogo->id));

        // Otro teléfono que sincroniza después se entera del borrado.
        $response = $this->postJson('/api/app/sync-app-data', ['since' => $antes])->assertOk();
        $this->assertTrue(collect($response->json('eliminados'))->contains(
            fn ($e) => $e['table_name'] === 'motivo_consulta_paciente' && $e['record_id'] === $fila->id,
        ));

        // Repetirlo no falla ni duplica el aviso.
        $this->sync([['table' => 'motivo_consulta_paciente', 'operation' => 'deleted', 'record_id' => $fila->id]]);
        $this->assertSame(1, \App\Models\SyncChange::where('table_name', 'motivo_consulta_paciente')
            ->where('record_id', $fila->id)->where('operation', 'deleted')->count());
    }

    public function test_no_se_puede_quitar_el_motivo_de_la_consulta_de_otro_medico(): void
    {
        $otro = $this->medico(autenticar: false);
        $consultaAjena = $this->consulta($otro, 1);
        $ajena = MotivoConsultaPaciente::create([
            'reg_medico' => $otro->reg_medico, 'codemotivo' => '0001',
            'nrohistoria' => $consultaAjena->numhistoria, 'nroconsulta' => $consultaAjena->nroconsulta,
        ]);

        $this->medico();
        $this->sync([['table' => 'motivo_consulta_paciente', 'operation' => 'deleted', 'record_id' => $ajena->id]]);

        $this->assertNotNull(MotivoConsultaPaciente::find($ajena->id));
    }
}
