<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tablero de Laravel Pulse (`/pulse`, 2026-10-24).
 *
 * La regla de acceso vive en el gate `viewPulse` (`app/Providers/AppServiceProvider.php`), no en una
 * lista de correos ni en el entorno: **sólo el rol Root** entra; el resto de las cuentas recibe 403.
 * Un visitante sin sesión también recibe 403, porque las rutas de Pulse llevan su propio middleware
 * (`Authorize`) y no el `auth` del proyecto: no hay a dónde redirigirlo.
 *
 * `DatabaseTransactions` como el resto de los tests de features: la base de tests viene migrada y
 * sembrada desde `tests/bootstrap.php` (roles incluidos) y cada prueba hace rollback.
 */
class PulseWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Una cuenta activa con el rol pedido, sin la obligación de cambiar la clave. */
    private function conRol(string $rol): User
    {
        $user = User::create([
            'name'                 => 'Pulse ' . $rol,
            'email'                => 'pulse-' . strtolower($rol) . '-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_root_entra_al_tablero(): void
    {
        $respuesta = $this->actingAs($this->conRol('Root'))->get('/pulse');

        $respuesta->assertOk()->assertSee('Laravel Pulse');

        // La URI de Livewire del tablero tiene que ser **absoluta** (con `url()`): el layout del paquete la
        // emite relativa a la raíz del dominio y en QA, que vive bajo `/medic-report`, el AJAX daba 404.
        $respuesta->assertSee('data-update-uri="'.url('/livewire/update').'"', false);
    }

    public function test_medico_y_secretaria_reciben_403(): void
    {
        foreach (['Medico', 'Secretaria'] as $rol) {
            $this->app['auth']->forgetGuards();

            $this->actingAs($this->conRol($rol))
                ->get('/pulse')
                ->assertForbidden();
        }
    }

    public function test_un_invitado_recibe_403(): void
    {
        // Sin sesión no hay usuario para el gate: Pulse corta con 403 antes de cualquier redirección.
        $this->get('/pulse')->assertForbidden();
    }
}
