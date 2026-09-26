<?php

namespace Tests\Feature;

use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Catálogo de médicos del tenant en `sync-app-data` (ROADMAP.md Paso 22.B): `cola.medico` es la
 * `clave` de `evolucion`, no `medicos.id`, y el nombre se resuelve cruzando `evolucion.correo_med`
 * con `medicos.email` (aclarado por Alexander — no hay otra columna en común entre las dos tablas).
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: mismo motivo que `ConfiguracionMedicoTest`.
 */
class MedicosDelTenantTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(string $regMedico, bool $autenticar = false, string $name = 'Doctora'): Medico
    {
        $email = 'medico-mdt-' . uniqid() . '@example.com';
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => $name,
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

    public function test_un_solo_medico_resuelve_su_nombre_por_correo(): void
    {
        $regMedico = 'test-mdt-' . uniqid();
        $medico = $this->medico($regMedico, autenticar: true, name: 'Carlos');
        Evolucion::create([
            'reg_medico' => $regMedico,
            'clave' => 1,
            'correo_med' => $medico->email,
            'especialidad' => 'Ginecología',
        ]);

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $this->assertEquals([[
            'clave' => 1,
            'id' => $medico->id,
            'name' => 'Carlos',
            'lastname' => 'De Prueba',
            'especialidad' => 'Ginecología',
        ]], $response->json('medicos'));
    }

    public function test_dos_medicos_del_mismo_tenant_aparecen_los_dos_con_su_propia_clave(): void
    {
        // `medicos.reg_medico` es único por médico (su propio registro, no el tenant compartido —
        // ver el comentario "temporal, tiende a cambiar" en el modelo `Medico`): un médico que
        // además atiende en el consultorio de otro se enlaza con una fila de `MedicoRegistro`
        // aparte, no reusando esa columna. `$regMedicoCompartido` es el tenant real (el de la
        // instancia de PowerBuilder), no el de ningún médico en particular.
        $regMedicoCompartido = 'test-mdt-' . uniqid();
        $medicoA = $this->medico($regMedicoCompartido, autenticar: true, name: 'Carlos');

        $medicoB = $this->medico('test-mdt-b-' . uniqid(), name: 'Nersa');
        MedicoRegistro::create(['medico_id' => $medicoB->id, 'reg_medico' => $regMedicoCompartido]);

        Evolucion::create(['reg_medico' => $regMedicoCompartido, 'clave' => 1, 'correo_med' => $medicoA->email]);
        Evolucion::create(['reg_medico' => $regMedicoCompartido, 'clave' => 2, 'correo_med' => $medicoB->email]);

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $nombrePorClave = collect($response->json('medicos'))->pluck('name', 'clave');
        $this->assertSame(['Carlos', 'Nersa'], [$nombrePorClave[1], $nombrePorClave[2]]);
    }

    public function test_correo_sin_match_deja_id_y_nombre_en_null_pero_conserva_la_clave(): void
    {
        $regMedico = 'test-mdt-' . uniqid();
        $this->medico($regMedico, autenticar: true);
        Evolucion::create([
            'reg_medico' => $regMedico,
            'clave' => 3,
            'correo_med' => 'no-existe-mdt@example.com',
            'especialidad' => 'Neurología',
        ]);

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $fila = collect($response->json('medicos'))->firstWhere('clave', 3);
        $this->assertNull($fila['id']);
        $this->assertNull($fila['name']);
        $this->assertSame('Neurología', $fila['especialidad']);
    }

    public function test_otro_tenant_no_aparece_en_el_catalogo(): void
    {
        $regMedico = 'test-mdt-' . uniqid();
        $this->medico($regMedico, autenticar: true);

        $otroTenant = 'test-mdt-otro-' . uniqid();
        Evolucion::create(['reg_medico' => $otroTenant, 'clave' => 1, 'correo_med' => 'quien-sea@example.com']);

        $response = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $this->assertSame([], $response->json('medicos'));
    }
}
