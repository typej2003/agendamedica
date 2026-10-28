<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Servicios;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\Specialty;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\ServicioService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El downgrade de plan (2026-10-28): un plan que no cubre al equipo se rechaza; el dueño quita a un médico
 * (sólo el vínculo) y recién entonces puede bajar. Renovar el mismo plan se permite aunque el equipo esté
 * por encima, para no cortar el servicio.
 */
class DowngradeDePlanWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array{user:User, medico:Medico, especialidad:Specialty} */
    private function doctor(string $prefijo): array
    {
        $especialidad = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $sufijo = substr(uniqid(), -6);
        $reg = 'baja-' . $prefijo . '-' . $sufijo;

        $user = User::create([
            'name' => 'Doctor ' . $prefijo, 'email' => $reg . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctor', 'lastname' => $prefijo,
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return ['user' => $user, 'medico' => $medico, 'especialidad' => $especialidad];
    }

    private function como(User $user, Medico $medico, Specialty $especialidad): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $especialidad->id,
            'office_id' => null,
        ]])->actingAs($user);
    }

    private function planDelEquipo(string $reg, string $codigo): void
    {
        app(ServicioService::class)->reemplazar($reg, Plan::porCodigo($codigo), 0, 'prueba');
    }

    private function metodoDePrueba(): MetodoPago
    {
        $cuenta = CuentaBancaria::create([
            'propietario' => CuentaBancaria::PLATAFORMA,
            'reg_medico' => null,
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::CUENTA,
            'banco_codigo' => '0172',
            'numero' => '01340123456789012345',
            'titular' => 'Doctorisimo, C.A.',
            'documento_tipo' => 'J',
            'documento_numero' => 'J-12345678-9',
            'predeterminada' => true,
            'activa' => true,
        ]);

        return MetodoPago::create([
            'propietario' => MetodoPago::PLATAFORMA,
            'reg_medico' => null,
            'pasarela' => 'sypago',
            'variante' => 'debito_inmediato',
            'nombre' => 'SyPago · Débito inmediato',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'credenciales' => [
                'access_token' => 'token-de-prueba',
                'host' => 'https://app.sypago.net:8086/api/v1/',
            ],
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::AMBOS,
            'orden' => 1,
        ]);
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin', 'admin-baja-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    public function test_el_plan_que_no_cubre_al_equipo_se_deshabilita_y_no_se_compra(): void
    {
        TasaDeCambio::fijar('36,5');
        $duenio = $this->doctor('duenio');
        $this->planDelEquipo($duenio['medico']->reg_medico, 'equipo-3');
        $dos = $this->doctor('dos');
        $tres = $this->doctor('tres');
        $cuentas = app(CuentaService::class);
        $cuentas->asignarRegistro($dos['medico'], $duenio['medico']->reg_medico);
        $cuentas->asignarRegistro($tres['medico'], $duenio['medico']->reg_medico);

        $this->como($duenio['user'], $duenio['medico'], $duenio['especialidad'])
            ->get(route('clinica.plan'))
            ->assertOk()
            ->assertSee('No cabe tu equipo')
            ->assertSee('Tu equipo tiene 3 médicos')
            ->assertSee('Renovar');

        $metodo = $this->metodoDePrueba();

        $this->como($duenio['user'], $duenio['medico'], $duenio['especialidad'])
            ->from(route('clinica.plan'))
            ->post(route('clinica.plan.comprar'), [
                'plan_id' => Plan::porCodigo('equipo-2')->id,
                'metodo_pago_id' => $metodo->id,
            ])
            ->assertRedirect(route('clinica.plan'))
            ->assertSessionHas('error');

        $this->assertSame(0, Pago::count());
    }

    public function test_el_panel_rechaza_el_reemplazo_por_un_plan_mas_chico(): void
    {
        $duenio = $this->doctor('panel-duenio');
        $this->planDelEquipo($duenio['medico']->reg_medico, 'equipo-3');
        $dos = $this->doctor('panel-dos');
        $tres = $this->doctor('panel-tres');
        app(CuentaService::class)->asignarRegistro($dos['medico'], $duenio['medico']->reg_medico);
        app(CuentaService::class)->asignarRegistro($tres['medico'], $duenio['medico']->reg_medico);

        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirReemplazar', $duenio['medico']->id)
            ->set('planElegido', Plan::porCodigo('equipo-2')->id)
            ->set('motivo', 'Bajar de plan')
            ->call('reemplazar')
            ->assertHasErrors(['planElegido']);

        $this->assertSame(
            Plan::porCodigo('equipo-3')->id,
            (int) RegMedicoServicio::where('reg_medico', $duenio['medico']->reg_medico)
                ->orderByDesc('vence_el')->orderByDesc('id')->value('plan_id'),
        );
    }

    public function test_renovar_el_mismo_plan_se_permite_aunque_el_equipo_este_por_encima(): void
    {
        $duenio = $this->doctor('renueva');
        $this->planDelEquipo($duenio['medico']->reg_medico, 'equipo-3');
        $dos = $this->doctor('renueva-dos');
        $tres = $this->doctor('renueva-tres');
        app(CuentaService::class)->asignarRegistro($dos['medico'], $duenio['medico']->reg_medico);
        app(CuentaService::class)->asignarRegistro($tres['medico'], $duenio['medico']->reg_medico);

        // Simula un equipo por encima del plan actual: baja el plan a 2 dejando los 3 médicos.
        app(ServicioService::class)->reemplazar($duenio['medico']->reg_medico, Plan::porCodigo('equipo-2'), 0, 'legado');

        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirRenovar', $duenio['medico']->id)
            ->set('planElegido', Plan::porCodigo('equipo-2')->id)
            ->call('renovar')
            ->assertHasNoErrors();

        $this->assertSame(
            Plan::porCodigo('equipo-2')->id,
            (int) RegMedicoServicio::where('reg_medico', $duenio['medico']->reg_medico)
                ->orderByDesc('vence_el')->orderByDesc('id')->value('plan_id'),
        );
    }

    public function test_el_duenio_quita_a_un_medico_y_entonces_si_puede_bajar(): void
    {
        $duenio = $this->doctor('quita');
        $this->planDelEquipo($duenio['medico']->reg_medico, 'equipo-3');
        $dos = $this->doctor('quita-dos');
        $tres = $this->doctor('quita-tres');
        app(CuentaService::class)->asignarRegistro($dos['medico'], $duenio['medico']->reg_medico);
        app(CuentaService::class)->asignarRegistro($tres['medico'], $duenio['medico']->reg_medico);

        $this->como($duenio['user'], $duenio['medico'], $duenio['especialidad'])
            ->delete(route('clinica.equipo.quitar', $tres['medico']->id))
            ->assertRedirect(route('clinica.equipo'));

        $this->assertDatabaseMissing('medico_registros', [
            'medico_id' => $tres['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);
        $this->assertNotNull(Medico::find($tres['medico']->id), 'Quitar del equipo no borra la ficha del médico.');

        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirReemplazar', $duenio['medico']->id)
            ->set('planElegido', Plan::porCodigo('equipo-2')->id)
            ->set('motivo', 'Ya somos dos')
            ->call('reemplazar')
            ->assertHasNoErrors();

        $this->assertSame(
            Plan::porCodigo('equipo-2')->id,
            (int) RegMedicoServicio::where('reg_medico', $duenio['medico']->reg_medico)
                ->orderByDesc('vence_el')->orderByDesc('id')->value('plan_id'),
        );
    }

    public function test_el_duenio_no_se_puede_quitar_a_si_mismo_y_un_afiliado_no_quita(): void
    {
        $duenio = $this->doctor('protege');
        $this->planDelEquipo($duenio['medico']->reg_medico, 'equipo-3');
        $afiliado = $this->doctor('protege-afiliado');
        app(CuentaService::class)->asignarRegistro($afiliado['medico'], $duenio['medico']->reg_medico);

        $this->como($duenio['user'], $duenio['medico'], $duenio['especialidad'])
            ->from(route('clinica.equipo'))
            ->delete(route('clinica.equipo.quitar', $duenio['medico']->id))
            ->assertRedirect(route('clinica.equipo'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('medico_registros', [
            'medico_id' => $duenio['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);

        // El afiliado ve el equipo pero no puede quitar a nadie.
        $this->como($afiliado['user'], $duenio['medico'], $duenio['especialidad'])
            ->delete(route('clinica.equipo.quitar', $duenio['medico']->id))
            ->assertForbidden();
    }
}
