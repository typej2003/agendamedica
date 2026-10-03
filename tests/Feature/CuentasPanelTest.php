<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Cuentas;
use App\Models\Medico;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Sección "Usuarios" del panel (`/admin/cuentas`, componente Livewire `Admin\Cuentas`).
 *
 * La lógica de cuentas ya está probada en `CuentasTest`; acá se prueba la pantalla: quién entra, qué lista,
 * y que cada acción llegue al servicio y muestre la clave generada UNA vez.
 *
 * `DatabaseTransactions` (ver MedicAPI/AGENTS.md): la base sqlite de desarrollo trae datos reales.
 */
class CuentasPanelTest extends TestCase
{
    use DatabaseTransactions;

    private CuentaService $cuentas;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->cuentas = app(CuentaService::class);
    }

    /** Administrador que ya cambió su clave (si no, el middleware lo manda a /cambiar-password). */
    private function admin(): User
    {
        $user = $this->cuentas->crearAdministrador('Admin Panel', 'admin-panel-' . uniqid() . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function medicoSinPermisos(): User
    {
        $r = $this->cuentas->crearMedico([
            'name' => 'Medico', 'lastname' => 'Comun', 'email' => 'comun-' . uniqid() . '@example.com',
            'reg_medico' => 'test-comun-' . uniqid(),
        ]);
        $r['user']->forceFill(['must_change_password' => false])->save();

        return $r['user'];
    }

    /* ------------------------------------------------------------------ */
    /* Acceso                                                              */
    /* ------------------------------------------------------------------ */

    public function test_sin_sesion_va_al_login_y_un_medico_comun_recibe_403(): void
    {
        $this->get('/admin/cuentas')->assertRedirect('/login');

        $this->actingAs($this->medicoSinPermisos())->get('/admin/cuentas')->assertForbidden();
    }

    public function test_entran_los_administradores_y_los_root(): void
    {
        $this->actingAs($this->admin())->get('/admin/cuentas')->assertOk()->assertSee('Nuevo médico');

        $this->app['auth']->forgetGuards();
        $root = $this->admin();
        $root->syncRoles(['Root']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($root->fresh())->get('/admin/cuentas')->assertOk();
    }

    public function test_el_enlace_usuarios_del_panel_lateral_apunta_a_la_seccion_nueva_solo_para_administradores(): void
    {
        $this->actingAs($this->admin());
        // El enlace viejo (`/users`) ya no está: se busca con las comillas del href porque `/users/permissions` sigue existiendo.
        Livewire::test('layouts.aside')
            ->assertSee(route('admin.cuentas'))
            ->assertDontSee('href="' . route('users.index') . '"', false);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->medicoSinPermisos());
        Livewire::test('layouts.aside')->assertDontSee(route('admin.cuentas'));
    }

    public function test_las_acciones_tambien_se_comprueban_en_cada_peticion(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $correo = 'colado-' . uniqid() . '@example.com';

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'administrador')
            ->set('name', 'Colado')->set('email', $correo);

        // Le quitan el rol a mitad de sesión: la siguiente acción de Livewire ya no puede surtir efecto
        // (la ruta solo se comprueba al abrir la página; cada acción es una petición aparte).
        $admin->removeRole('Administrador');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin->fresh());

        $panel->call('guardarAlta');

        $this->assertNull(User::where('email', $correo)->first(), 'Una acción sin permiso no debe crear nada.');
        $this->assertNull($panel->get('claveGenerada'));
    }

    /* ------------------------------------------------------------------ */
    /* Listado                                                             */
    /* ------------------------------------------------------------------ */

    public function test_lista_medicos_con_su_estado_y_administradores_en_su_pestana(): void
    {
        $admin = $this->admin();
        $conCuenta = $this->cuentas->crearMedico([
            'name' => 'Con', 'lastname' => 'Cuenta', 'email' => 'con-' . uniqid() . '@example.com', 'reg_medico' => 'test-con-' . uniqid(),
        ]);
        $sinCuenta = Medico::create([
            'name' => 'Sin', 'lastname' => 'Cuenta', 'email' => 'sin-' . uniqid() . '@example.com', 'reg_medico' => 'test-sin-' . uniqid(),
        ]);

        $this->actingAs($admin);
        Livewire::test(Cuentas::class)
            ->set('search', 'test-con-')
            ->assertSee('Con Cuenta')
            ->assertSee('Clave temporal')
            ->set('search', $sinCuenta->reg_medico)
            ->assertSee('Sin Cuenta')
            ->assertSee('Sin acceso')
            ->assertSee('Crear acceso')
            ->set('search', '')
            ->set('pestana', 'administradores')
            ->set('search', $admin->email)
            ->assertSee('Admin Panel');
    }

    /* ------------------------------------------------------------------ */
    /* Alta                                                                */
    /* ------------------------------------------------------------------ */

    public function test_crear_medico_muestra_la_clave_una_sola_vez(): void
    {
        $this->actingAs($this->admin());
        $sufijo = uniqid();

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'medico')
            ->assertSet('modal', 'alta')
            ->set('name', 'Nuevo')->set('lastname', 'Doctor')
            ->set('email', "nuevo-{$sufijo}@example.com")->set('reg_medico', "test-nuevo-{$sufijo}")
            ->call('guardarAlta')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $mostrada = $panel->get('claveGenerada');
        $this->assertSame('Médico creado', $mostrada['titulo']);
        $this->assertSame("nuevo-{$sufijo}@example.com", $mostrada['correo']);
        $this->assertGreaterThanOrEqual(8, strlen($mostrada['clave']));

        $user = User::where('email', "nuevo-{$sufijo}@example.com")->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole('Medico'));
        $this->assertSame("test-nuevo-{$sufijo}", $user->medico->reg_medico);

        // Al cerrar el aviso la clave desaparece del componente.
        $panel->call('cerrarClave')->assertSet('claveGenerada', null);
    }

    public function test_crear_administrador_con_clave_propia(): void
    {
        $this->actingAs($this->admin());
        $correo = 'otro-admin-' . uniqid() . '@example.com';

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'administrador')
            ->set('name', 'Otro Admin')->set('email', $correo)->set('clave', 'ClaveInicial9')
            ->call('guardarAlta')
            ->assertHasNoErrors()
            ->assertSet('pestana', 'administradores');

        $this->assertSame('ClaveInicial9', $panel->get('claveGenerada')['clave']);
        $user = User::where('email', $correo)->firstOrFail();
        $this->assertTrue($user->esAdministrador());
        $this->assertTrue($user->must_change_password);
    }

    public function test_alta_con_datos_malos_muestra_errores_y_no_crea_nada(): void
    {
        $existente = $this->medicoSinPermisos();
        $this->actingAs($this->admin());
        $antes = User::count();

        Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'medico')
            ->call('guardarAlta')
            ->assertHasErrors(['name', 'lastname', 'email', 'reg_medico'])
            ->set('name', 'X')->set('lastname', 'Y')->set('reg_medico', 'test-' . uniqid())
            ->set('email', $existente->email)
            ->call('guardarAlta')
            ->assertHasErrors(['email'])
            ->assertSet('modal', 'alta')
            ->set('email', 'x-' . uniqid() . '@example.com')->set('clave', 'corta')
            ->call('guardarAlta')
            ->assertHasErrors(['password']);

        $this->assertSame($antes, User::count());
    }

    public function test_crear_acceso_a_un_medico_que_no_podia_entrar(): void
    {
        $medico = Medico::create([
            'name' => 'Sin', 'lastname' => 'Acceso', 'email' => 'sin-acceso-' . uniqid() . '@example.com', 'reg_medico' => 'test-sa-' . uniqid(),
        ]);
        $this->actingAs($this->admin());

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirAcceso', $medico->id)
            ->assertSet('modal', 'acceso')
            ->assertSet('email', $medico->email)
            ->call('guardarAcceso')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $this->assertSame('Acceso creado', $panel->get('claveGenerada')['titulo']);
        $this->assertNotNull($medico->fresh()->user_id);
    }

    /* ------------------------------------------------------------------ */
    /* Reseteo, bloqueo y roles                                            */
    /* ------------------------------------------------------------------ */

    public function test_resetear_clave_muestra_una_nueva_y_vuelve_a_pedir_cambiarla(): void
    {
        $objetivo = $this->medicoSinPermisos();
        $claveVieja = $objetivo->password;
        $this->actingAs($this->admin());

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirReset', $objetivo->id)
            ->assertSet('modal', 'reset')
            ->call('confirmarReset')
            ->assertSet('modal', null);

        $this->assertSame('Clave restablecida', $panel->get('claveGenerada')['titulo']);
        $this->assertNotSame($claveVieja, $objetivo->fresh()->password);
        $this->assertTrue($objetivo->fresh()->must_change_password);
    }

    public function test_bloquear_y_desbloquear(): void
    {
        $objetivo = $this->medicoSinPermisos();
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('abrirBloqueo', $objetivo->id)
            ->set('motivo', 'Falta de pago')
            ->call('confirmarBloqueo')
            ->assertHasNoErrors()
            ->assertSet('modal', null)
            ->assertSee('Bloqueado')
            ->call('desbloquear', $objetivo->id)
            ->assertDontSee('Bloqueado');

        $this->assertTrue($objetivo->fresh()->is_active);
    }

    public function test_no_deja_bloquearse_a_si_mismo_ni_dejar_al_sistema_sin_administradores(): void
    {
        User::administradores()->update(['is_active' => false]);
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(Cuentas::class)
            ->call('abrirBloqueo', $admin->id)
            ->call('confirmarBloqueo')
            ->assertHasErrors(['cuenta'])
            ->assertSet('modal', 'bloquear')
            ->call('cerrarModal')
            ->call('abrirQuitarAdmin', $admin->id)
            ->call('confirmarQuitarAdmin')
            ->assertHasErrors(['cuenta']);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->esAdministrador());
    }

    public function test_hacer_y_quitar_administrador_conservando_otros_roles(): void
    {
        $medico = $this->medicoSinPermisos();
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('hacerAdministrador', $medico->id)
            ->assertHasNoErrors();

        $this->assertTrue($medico->fresh()->esAdministrador());
        $this->assertTrue($medico->fresh()->hasRole('Medico'));

        Livewire::test(Cuentas::class)
            ->call('abrirQuitarAdmin', $medico->id)
            ->call('confirmarQuitarAdmin')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($medico->fresh()->esAdministrador());
        $this->assertTrue($medico->fresh()->hasRole('Medico'));
    }
}
