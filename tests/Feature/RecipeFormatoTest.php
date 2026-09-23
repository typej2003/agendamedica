<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\RecipeFormato;
use App\Models\User;
use App\Models\Vademecum;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Formato de impresión del récipe + firma/sello (ROADMAP.md Paso 18.A), y lo que el sync le manda al
 * teléfono para imprimir (`formato_recipe`, `vademecum`).
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: mismo motivo que `ConfiguracionMedicoTest`.
 */
class RecipeFormatoTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/app/configuracion/formato-recipe';

    private function medicoAutenticado(): Medico
    {
        $regMedico = 'test-rf-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-rf-' . uniqid() . '@example.com',
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

    public function test_sin_fila_el_sync_devuelve_los_defaults_del_legado(): void
    {
        $this->medicoAutenticado();

        $response = $this->postJson('/api/app/sync-app-data', []);

        $response->assertOk();
        $response->assertJsonPath('formato_recipe.elementos.logo.alineacion', 'izquierda');
        $response->assertJsonPath('formato_recipe.elementos.medico.alineacion', 'centro');
        $response->assertJsonPath('formato_recipe.elementos.especialidad.negrita', true);
        $response->assertJsonPath('formato_recipe.color_linea', 'negro');
        $response->assertJsonPath('formato_recipe.firma_url', null);
        $response->assertJsonPath('formato_recipe.sello_posicion', 'centro');
    }

    public function test_actualizacion_parcial_de_un_elemento_no_pisa_el_resto(): void
    {
        $medico = $this->medicoAutenticado();

        $this->postJson(self::URL, [
            'elementos' => ['medico' => ['alineacion' => 'derecha', 'fuente' => 'tinos']],
        ])->assertCreated();

        $response = $this->postJson(self::URL, [
            'elementos' => ['medico' => ['negrita' => true]],
            'color_linea' => 'azul',
        ]);

        $response->assertOk();
        // Lo que se mandó antes para el mismo elemento se conserva.
        $response->assertJsonPath('data.elementos.medico.alineacion', 'derecha');
        $response->assertJsonPath('data.elementos.medico.fuente', 'tinos');
        $response->assertJsonPath('data.elementos.medico.negrita', true);
        // Los elementos que nunca se tocaron salen con sus defaults.
        $response->assertJsonPath('data.elementos.rif.negrita', true);
        $response->assertJsonPath('data.color_linea', 'azul');

        $this->assertSame(1, RecipeFormato::where('reg_medico', $medico->regMedicoPrincipal())->count());
    }

    public function test_los_booleanos_de_multipart_se_guardan_como_booleanos(): void
    {
        $medico = $this->medicoAutenticado();

        // Así llegan desde el teléfono (FormData): texto, no booleano JSON.
        $this->post(self::URL, [
            'elementos' => ['rif' => ['negrita' => '0', 'visible' => '1']],
        ], ['Accept' => 'application/json'])->assertCreated();

        $guardado = RecipeFormato::where('reg_medico', $medico->regMedicoPrincipal())->first()->elementos;
        $this->assertFalse($guardado['rif']['negrita']);
        $this->assertTrue($guardado['rif']['visible']);
    }

    public function test_descarta_elementos_y_opciones_desconocidos(): void
    {
        $medico = $this->medicoAutenticado();

        $this->postJson(self::URL, [
            'elementos' => [
                'medico' => ['alineacion' => 'centro', 'color' => 'rojo'],
                'inventado' => ['alineacion' => 'centro'],
            ],
        ])->assertCreated();

        $guardado = RecipeFormato::where('reg_medico', $medico->regMedicoPrincipal())->first()->elementos;
        $this->assertArrayNotHasKey('inventado', $guardado);
        $this->assertArrayNotHasKey('color', $guardado['medico']);
    }

    public function test_rechaza_valores_fuera_de_dominio(): void
    {
        $this->medicoAutenticado();

        $this->postJson(self::URL, ['elementos' => ['medico' => ['fuente' => 'Comic Sans MS']]])
            ->assertStatus(422);
        $this->postJson(self::URL, ['elementos' => ['logo' => ['tamano' => 'gigante']]])
            ->assertStatus(422);
        $this->postJson(self::URL, ['color_linea' => 'violeta'])->assertStatus(422);
    }

    public function test_sube_la_firma_reemplazarla_borra_la_anterior_y_se_puede_eliminar(): void
    {
        Storage::fake('public');
        $medico = $this->medicoAutenticado();
        $ruta = fn () => RecipeFormato::where('reg_medico', $medico->regMedicoPrincipal())->first()->firma;

        $this->postJson(self::URL, ['firma' => UploadedFile::fake()->image('firma1.png')])
            ->assertCreated()
            ->assertJson(fn ($json) => $json->whereType('data.firma_url', 'string')->etc());
        $primera = $ruta();
        Storage::disk('public')->assertExists($primera);

        $this->postJson(self::URL, ['firma' => UploadedFile::fake()->image('firma2.png')])->assertOk();
        $segunda = $ruta();
        Storage::disk('public')->assertMissing($primera);
        Storage::disk('public')->assertExists($segunda);

        $this->postJson(self::URL, ['firma_eliminar' => true])
            ->assertOk()
            ->assertJsonPath('data.firma_url', null);
        Storage::disk('public')->assertMissing($segunda);
    }

    public function test_el_sync_manda_el_vademecum_del_medico_y_no_el_de_otros(): void
    {
        $medico = $this->medicoAutenticado();
        Vademecum::create([
            'reg_medico' => $medico->regMedicoPrincipal(),
            'codemedicina' => 'T001',
            'nombregenerico' => 'PARACETAMOL',
            'nombrecomercial' => 'ATAMEL',
        ]);
        Vademecum::create([
            'reg_medico' => 'otro-medico-' . uniqid(),
            'codemedicina' => 'T002',
            'nombregenerico' => 'AJENO',
        ]);

        $response = $this->postJson('/api/app/sync-app-data', []);

        $response->assertOk();
        $codigos = collect($response->json('vademecum'))->pluck('codemedicina');
        $this->assertTrue($codigos->contains('T001'));
        $this->assertFalse($codigos->contains('T002'));
    }

    public function test_rechaza_sin_autenticacion(): void
    {
        $this->postJson(self::URL, ['color_linea' => 'azul'])->assertStatus(401);
    }
}
