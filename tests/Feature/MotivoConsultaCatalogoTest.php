<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MotivoConsulta;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alta de un motivo en el catálogo desde el app (ROADMAP.md Paso 18.B2): `POST /api/app/motivos-consulta`,
 * online-only. El servidor le pone el código y no duplica descripciones.
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: mismo motivo que `CreacionesClinicasTest`.
 */
class MotivoConsultaCatalogoTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true, bool $claveTemporal = false): Medico
    {
        $regMedico = 'test-cat-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-cat-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        if ($claveTemporal) {
            $user->forceFill(['must_change_password' => true])->save();
        }
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

    private function motivo(Medico $medico, string $codigo, string $descripcion): MotivoConsulta
    {
        return MotivoConsulta::create([
            'reg_medico' => $medico->reg_medico,
            'codemotivo' => $codigo,
            'descripcion' => $descripcion,
        ]);
    }

    private function crear(string $descripcion)
    {
        return $this->postJson('/api/app/motivos-consulta', ['descripcion' => $descripcion]);
    }

    public function test_un_motivo_nuevo_recibe_el_siguiente_codigo_en_mayusculas(): void
    {
        $medico = $this->medico();
        $this->motivo($medico, '0001', 'UNO');
        $this->motivo($medico, '0007', 'SIETE');

        $response = $this->crear('  dolor   de cabeza ')->assertCreated();

        $response->assertJson(['codemotivo' => '0008', 'descripcion' => 'DOLOR DE CABEZA', 'creado' => true]);
        $motivo = MotivoConsulta::findOrFail($response->json('id'));
        $this->assertSame($medico->reg_medico, $motivo->reg_medico);
    }

    public function test_el_primer_motivo_de_un_catalogo_vacio_es_el_0001(): void
    {
        $this->medico();

        $this->crear('Control')->assertCreated()->assertJson(['codemotivo' => '0001']);
    }

    public function test_un_motivo_con_la_misma_descripcion_devuelve_el_existente_sin_duplicar(): void
    {
        $medico = $this->medico();
        $existente = $this->motivo($medico, '0003', 'DOLOR PÉLVICO');

        $this->crear('dolor pélvico')
            ->assertOk()
            ->assertJson(['id' => $existente->id, 'codemotivo' => '0003', 'creado' => false]);

        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_dos_usuarios_del_mismo_consultorio_que_crean_el_mismo_motivo_reciben_el_mismo_id(): void
    {
        $medico = $this->medico();

        $primero = $this->crear('Dolor pélvico')->assertCreated();
        $segundo = $this->crear(' DOLOR  PÉLVICO ')->assertOk();

        $this->assertSame($primero->json('id'), $segundo->json('id'));
        $this->assertSame($primero->json('codemotivo'), $segundo->json('codemotivo'));
        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_catalogo_de_otro_medico_no_cuenta_ni_se_toca(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajeno = $this->motivo($otro, '0005', 'DOLOR PELVICO');

        $medico = $this->medico();
        $response = $this->crear('Dolor pelvico')->assertCreated();

        // Catálogo propio: código propio (el primero), y el del otro médico queda intacto.
        $this->assertSame('0001', $response->json('codemotivo'));
        $this->assertNotSame($ajeno->id, $response->json('id'));
        $this->assertSame($medico->reg_medico, MotivoConsulta::find($response->json('id'))->reg_medico);
        $this->assertSame(1, MotivoConsulta::where('reg_medico', $otro->reg_medico)->count());
    }

    public function test_el_motivo_creado_baja_en_el_sync_con_su_id_real(): void
    {
        $this->medico();
        $id = $this->crear('Prurito')->assertCreated()->json('id');

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $this->assertTrue(collect($response->json('motivos_consulta'))->contains('id', $id));
    }

    public function test_descripcion_vacia_o_demasiado_larga_se_rechaza(): void
    {
        $medico = $this->medico();

        $this->crear('')->assertStatus(422);
        $this->crear('   ')->assertStatus(422);
        $this->crear(str_repeat('A', 41))->assertStatus(422)->assertJsonValidationErrors('descripcion');
        $this->crear(str_repeat('A', 40))->assertCreated();

        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->crear('Prurito')->assertUnauthorized();
    }

    public function test_con_clave_temporal_no_deja_pasar(): void
    {
        $this->medico(claveTemporal: true);

        $this->crear('Prurito')->assertForbidden()->assertJson(['code' => 'password_change_required']);
    }
}
