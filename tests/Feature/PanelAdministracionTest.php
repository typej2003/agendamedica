<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El Escritorio del panel (`/dashboard`).
 *
 * Los **dos** roles de administración (`Root` y `Administrador`) ven el mismo panel con sus indicadores.
 * El `Administrador` es la cuenta que crea `php artisan cuentas:admin` para arrancar el panel: hasta el
 * 2026-10-13 caía en el andamio que Livewire deja al crear un componente (`dashboard.admin-dashboard`,
 * `<div>{{-- Do your work, then step back. --}}</div>`, nunca implementado) y su pantalla quedaba en
 * blanco, con el menú de administración al costado y sin nada adentro. Ver `DashboardController`.
 *
 * `DatabaseTransactions` por la razón de siempre (ver MedicAPI/AGENTS.md).
 */
class PanelAdministracionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'panel-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_los_dos_roles_de_administracion_ven_los_indicadores_del_panel(): void
    {
        foreach (User::ROLES_ADMIN as $rol) {
            $html = (string) $this->actingAs($this->usuario($rol))->get('/dashboard')->assertOk()->getContent();

            $this->assertStringContainsString('Panel de administración', $html, "{$rol}: el encabezado del panel");
            $this->assertStringContainsString('Usuarios totales', $html, "{$rol}: las tarjetas de indicadores");
            $this->assertStringContainsString('Usuarios activos en este momento', $html, "{$rol}: la tabla de conectados");
        }
    }
}
