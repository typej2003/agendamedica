<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Latidos as PanelDeLatidos;
use App\Latidos\Latidos as ServicioDeLatidos;
use App\Models\Latido;
use App\Models\MovimientoDeLatidos;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Latidos en el panel** (`/admin/latidos`, 2026-10-31): lista, detalle y ajuste manual.
 *
 * Lo que se fija: sólo entra Root o Administrador; el ajuste manual es **sólo Root** y deja autor y motivo
 * en el libro. Un Administrador puede ver, pero no tocar los Latidos.
 */
class LatidosAdminTest extends TestCase
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
            'name' => 'Usuario de Latidos',
            'email' => 'admin-lat-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_acceso_solo_para_administradores_y_enlace_en_el_panel(): void
    {
        $this->get('/admin/latidos')->assertRedirect('/login');

        $this->actingAs($this->usuario('Medico'))->get('/admin/latidos')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->usuario('Root'));
        $this->get('/admin/latidos')->assertOk()->assertSee('Latidos');
        Livewire::test('layouts.aside')->assertSee(route('admin.latidos'));
    }

    public function test_la_lista_muestra_los_latidos_del_consultorio(): void
    {
        app(ServicioDeLatidos::class)->acreditar('MPPS-LAT-ADMIN', 1200, MovimientoDeLatidos::PROMOCION, 'promo:admin');

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(PanelDeLatidos::class)
            ->assertSee('MPPS-LAT-ADMIN')
            ->assertSee('1.200 Latidos');
    }

    public function test_root_puede_ajustar_y_queda_auditado(): void
    {
        app(ServicioDeLatidos::class)->acreditar('MPPS-LAT-AJUSTE', 1000, MovimientoDeLatidos::PROMOCION, 'promo:ajuste');
        $latido = Latido::where('reg_medico', 'MPPS-LAT-AJUSTE')->firstOrFail();
        $root = $this->usuario('Root');

        $this->actingAs($root);

        Livewire::test(PanelDeLatidos::class)
            ->call('abrir', $latido->id)
            ->set('ajusteLatidos', 250)
            ->set('ajusteMotivo', 'Cortesía autorizada en soporte')
            ->call('ajustar')
            ->assertHasNoErrors();

        $this->assertSame(1250, (int) $latido->fresh()->disponibles);

        $movimiento = MovimientoDeLatidos::where('tipo', MovimientoDeLatidos::AJUSTE)->latest('id')->firstOrFail();
        $this->assertSame(250, (int) $movimiento->latidos);
        $this->assertSame($root->id, (int) $movimiento->autor_id);
        $this->assertSame('Cortesía autorizada en soporte', $movimiento->nota);
    }

    public function test_un_administrador_no_puede_ajustar(): void
    {
        app(ServicioDeLatidos::class)->acreditar('MPPS-LAT-SIN-AJUSTE', 1000, MovimientoDeLatidos::PROMOCION, 'promo:sin-ajuste');
        $latido = Latido::where('reg_medico', 'MPPS-LAT-SIN-AJUSTE')->firstOrFail();

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(PanelDeLatidos::class)
            ->call('abrir', $latido->id)
            ->set('ajusteLatidos', 250)
            ->set('ajusteMotivo', 'No debería poder')
            ->call('ajustar')
            ->assertForbidden();

        $this->assertSame(1000, (int) $latido->fresh()->disponibles);
    }
}
