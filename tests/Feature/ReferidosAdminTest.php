<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Referidos as PanelDeReferidos;
use App\Latidos\Latidos;
use App\Models\ConfiguracionLatidos;
use App\Models\Medico;
use App\Models\Referido;
use App\Models\SolicitudMedico;
use App\Models\User;
use App\Referidos\AcreditarReferido;
use App\Referidos\CodigosDeReferido;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Referidos en el panel** (`/admin/referidos`, fase 4): configuración, lista y anulación.
 *
 * Lo que se fija: sólo entra Root o Administrador; los valores del premio se guardan en Latidos; la lista
 * muestra el referido; y anular devuelve los Latidos a los dos y deja el motivo.
 */
class ReferidosAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
    }

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name' => 'Usuario de referidos', 'email' => 'admin-ref-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function referente(): array
    {
        $user = $this->usuario('Medico');
        $reg = 'ref-admin-' . substr(uniqid(), -6);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. Admin', 'lastname' => 'Referente',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg, 'is_active' => true,
        ]);
        $codigo = app(CodigosDeReferido::class)->para($reg);

        return [$user, $medico, $codigo->codigo];
    }

    private function referidoAcreditado(string $referente, string $codigo): Referido
    {
        $solicitud = SolicitudMedico::create([
            'prefijo' => 'Dr.', 'nombres' => 'Nuevo', 'apellidos' => 'Admin',
            'cedula' => '9' . random_int(1000000, 9999999), 'mpps' => (string) random_int(100000, 999999),
            'telefono' => '0414-5556677', 'email' => 'colega-admin-' . uniqid() . '@example.com',
            'estado' => SolicitudMedico::PENDIENTE, 'referente_reg_medico' => $referente, 'codigo_referido' => $codigo,
        ]);
        $colega = Medico::create([
            'name' => 'Nuevo', 'lastname' => 'Admin', 'reg_medico' => 'MPPS-ADMIN-' . random_int(1000, 9999),
            'is_active' => true,
        ]);

        return app(AcreditarReferido::class)->ejecutar($solicitud, $colega->reg_medico, $colega, false);
    }

    public function test_acceso_solo_para_administradores_y_enlace_en_el_panel(): void
    {
        $this->get('/admin/referidos')->assertRedirect('/login');

        $this->actingAs($this->usuario('Medico'))->get('/admin/referidos')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->usuario('Root'));
        $this->get('/admin/referidos')->assertOk()->assertSee('Referidos');
        Livewire::test('layouts.aside')->assertSee(route('admin.referidos'));
    }

    public function test_guarda_la_configuracion_en_latidos(): void
    {
        $this->actingAs($this->usuario('Root'));

        Livewire::test(PanelDeReferidos::class)
            ->set('referidosActivos', false)
            ->set('latidosReferente', 2500)
            ->set('latidosReferido', 1500)
            ->set('vigenciaDias', 60)
            ->set('topeMensual', 5)
            ->set('urlDescarga', 'https://play.google.com/store/apps/details?id=com.ddrsistemas.doctorisimo')
            ->call('guardarConfiguracion')
            ->assertHasNoErrors();

        $config = ConfiguracionLatidos::actual();
        $this->assertFalse($config->referidosActivos());
        $this->assertSame(2500, $config->latidos_referente);
        $this->assertSame(1500, $config->latidos_referido);
        $this->assertSame(60, $config->vigencia_atribucion_dias);
        $this->assertSame(5, $config->tope_mensual_por_referente);
    }

    public function test_anular_devuelve_los_latidos_y_deja_el_motivo(): void
    {
        [$user, $referente, $codigo] = $this->referente();
        $referido = $this->referidoAcreditado($referente->reg_medico, $codigo);
        $this->assertSame(2000, app(Latidos::class)->disponibles($referente->reg_medico));

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(PanelDeReferidos::class)
            ->call('abrir', $referido->id)
            ->set('motivoAnulacion', 'Registro duplicado')
            ->call('anular')
            ->assertHasNoErrors();

        $this->assertSame(Referido::ANULADO, $referido->fresh()->estado);
        $this->assertSame('Registro duplicado', $referido->fresh()->motivo);
        $this->assertSame(0, app(Latidos::class)->disponibles($referente->reg_medico));
        $this->assertSame(0, app(Latidos::class)->disponibles($referido->referido_reg_medico));
    }
}
