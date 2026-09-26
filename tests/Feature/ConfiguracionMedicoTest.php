<?php

namespace Tests\Feature;

use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `DatabaseTransactions`, no `RefreshDatabase`: este proyecto no tiene un `.env.testing` propio
 * (ver MedicAPI/AGENTS.md) y correr migraciones acá pisaría la base sqlite de desarrollo local con
 * datos reales sembrados. La transacción deja todo exactamente como estaba al terminar.
 */
class ConfiguracionMedicoTest extends TestCase
{
    use DatabaseTransactions;

    private function medicoAutenticado(string $regMedico = 'test-reg-001'): Medico
    {
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-prueba-' . uniqid() . '@example.com',
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

        // El guard de esta API es 'api' (driver sanctum, ver config/auth.php), no el 'sanctum' que
        // usa Sanctum::actingAs() por default — sin el tercer argumento, `auth:api` no lo reconoce.
        Sanctum::actingAs($user, ['*'], 'api');

        return $medico;
    }

    public function test_crea_la_fila_de_evolucion_si_no_existia(): void
    {
        $medico = $this->medicoAutenticado();
        $this->assertNull(Evolucion::where('reg_medico', $medico->reg_medico)->first());

        $response = $this->postJson('/api/app/configuracion', [
            'especialidad' => 'Cardiologia',
            'ciudad' => 'Caracas',
            'rif' => 'J-11111111-1',
            'pie_recipe' => [
                'direccion' => 'Av. Siempre Viva 123',
                'telefono' => '0212-5551234',
                'correo' => 'contacto@example.com',
            ],
        ]);

        // 201: el propio JsonResource marca la respuesta como "creado" porque la fila de
        // evolucion no existía (Eloquent::wasRecentlyCreated).
        $response->assertCreated();
        $response->assertJsonPath('data.especialidad', 'Cardiologia');
        $response->assertJsonPath('data.ciudad', 'Caracas');
        $response->assertJsonPath('data.rif', 'J-11111111-1');
        $response->assertJsonPath('data.pie_recipe.direccion', 'Av. Siempre Viva 123');
        $response->assertJsonPath('data.pie_recipe.telefono', '0212-5551234');
        $response->assertJsonPath('data.pie_recipe.correo', 'contacto@example.com');

        $this->assertNotNull(Evolucion::where('reg_medico', $medico->reg_medico)->first());
    }

    public function test_una_actualizacion_parcial_no_borra_los_campos_que_no_mando(): void
    {
        $medico = $this->medicoAutenticado();
        Evolucion::create([
            'reg_medico' => $medico->reg_medico,
            'especialidad' => 'Pediatria',
            'ciudad' => 'Valencia',
            'clave' => 0,
        ]);

        $response = $this->postJson('/api/app/configuracion', ['rif' => 'J-22222222-2']);

        $response->assertOk();
        $response->assertJsonPath('data.rif', 'J-22222222-2');
        // Lo que no se mandó en esta petición sigue como estaba.
        $response->assertJsonPath('data.especialidad', 'Pediatria');
        $response->assertJsonPath('data.ciudad', 'Valencia');
    }

    public function test_sube_el_logo_y_reemplazarlo_borra_el_archivo_anterior(): void
    {
        Storage::fake('public');
        $medico = $this->medicoAutenticado();

        $primero = UploadedFile::fake()->image('logo1.png', 100, 100);
        $respuesta1 = $this->postJson('/api/app/configuracion', ['logo' => $primero]);
        $respuesta1->assertCreated(); // primera fila de evolucion para este médico
        $rutaGuardada1 = Evolucion::where('reg_medico', $medico->reg_medico)->first()->logo;
        Storage::disk('public')->assertExists($rutaGuardada1);
        $this->assertNotNull($respuesta1->json('data.logo_url'));

        $segundo = UploadedFile::fake()->image('logo2.png', 100, 100);
        $respuesta2 = $this->postJson('/api/app/configuracion', ['logo' => $segundo]);
        $respuesta2->assertOk();
        $rutaGuardada2 = Evolucion::where('reg_medico', $medico->reg_medico)->first()->logo;

        $this->assertNotSame($rutaGuardada1, $rutaGuardada2);
        Storage::disk('public')->assertExists($rutaGuardada2);
        Storage::disk('public')->assertMissing($rutaGuardada1);
    }

    public function test_guarda_las_plantillas_de_mensaje_sin_tocar_el_resto(): void
    {
        // Paso 23: el médico solo edita su propia plantilla — la resolución es la misma que ya usa
        // el resto de "Configuración" (la cuenta logueada), sin nada nuevo que agregar acá.
        $medico = $this->medicoAutenticado();
        Evolucion::create([
            'reg_medico' => $medico->reg_medico,
            'especialidad' => 'Pediatria',
            'clave' => 0,
        ]);

        $response = $this->postJson('/api/app/configuracion', [
            'plantilla_cita' => 'Hola {paciente}, recuerde su cita el {fecha} a las {hora}.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.plantilla_cita', 'Hola {paciente}, recuerde su cita el {fecha} a las {hora}.');
        $response->assertJsonPath('data.plantilla_cumple', null);
        // Lo que no se mandó en esta petición sigue como estaba (misma regla que el resto de campos).
        $response->assertJsonPath('data.especialidad', 'Pediatria');
    }

    public function test_rechaza_sin_autenticacion(): void
    {
        $response = $this->postJson('/api/app/configuracion', ['rif' => 'J-33333333-3']);

        $response->assertStatus(401);
    }

    public function test_rechaza_un_logo_que_no_es_imagen(): void
    {
        $this->medicoAutenticado();

        $response = $this->postJson('/api/app/configuracion', [
            'logo' => UploadedFile::fake()->create('documento.pdf', 100),
        ]);

        $response->assertStatus(422);
    }
}
