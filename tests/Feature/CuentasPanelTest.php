<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Cuentas;
use App\Models\Medico;
use App\Models\SyncCredential;
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
        // "Permisos de Usuario" tampoco: nunca funcionó y los roles se cambian desde la fila (menú de 3 puntos).
        Livewire::test('layouts.aside')
            ->assertSee(route('admin.cuentas'))
            ->assertDontSee('href="' . route('users.index') . '"', false)
            ->assertDontSee('Permisos de Usuario')
            ->assertDontSee(route('users.permissions'));

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
            // Quitarse el acceso de administración por la ventana de roles tampoco (único administrador activo).
            ->call('abrirRoles', $admin->id)
            ->set('rolesSeleccionados', ['Medico'])
            ->call('guardarRoles')
            ->assertHasErrors(['roles']);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->esAdministrador());
    }

    /* ------------------------------------------------------------------ */
    /* Menú de 3 puntos                                                    */
    /* ------------------------------------------------------------------ */

    public function test_cada_fila_trae_editar_y_un_menu_con_el_resto_de_acciones(): void
    {
        $r = $this->cuentas->crearMedico([
            'name' => 'Menu', 'lastname' => 'Fila', 'email' => 'menu-' . uniqid() . '@example.com', 'reg_medico' => 'test-menu-' . uniqid(),
        ]);
        $this->actingAs($this->admin());

        $panel = Livewire::test(Cuentas::class)->set('search', $r['medico']->reg_medico);

        $panel->assertSee('Editar')
            ->assertSee('bi-three-dots-vertical', false)
            ->assertSee('Roles')
            ->assertSee('Resetear clave')
            ->assertSee('Generar API key')
            ->assertSee('Bloquear')
            // Los botones sueltos de antes ya no están.
            ->assertDontSee('Hacer admin')
            ->assertDontSee('Quitar admin');
    }

    public function test_un_medico_sin_cuenta_tiene_crear_acceso_y_el_menu_solo_con_api_key(): void
    {
        $medico = Medico::create([
            'name' => 'Sin', 'lastname' => 'Menu', 'email' => 'sinmenu-' . uniqid() . '@example.com', 'reg_medico' => 'test-sm-' . uniqid(),
        ]);
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)->set('search', $medico->reg_medico)
            ->assertSee('Crear acceso')
            ->assertSee('Generar API key')
            ->assertDontSee('Resetear clave')
            ->assertDontSee('Bloquear');
    }

    /* ------------------------------------------------------------------ */
    /* Editar datos                                                        */
    /* ------------------------------------------------------------------ */

    public function test_editar_medico_abre_el_formulario_con_sus_datos_y_los_guarda(): void
    {
        $r = $this->cuentas->crearMedico([
            'name' => 'Ana', 'lastname' => 'Vieja', 'email' => 'ana-' . uniqid() . '@example.com',
            'reg_medico' => 'test-ed-' . uniqid(), 'phone' => '0212-1111111', 'license_number' => 'LIC-1',
        ]);
        $r['medico']->forceFill(['phone' => '0212-1111111', 'license_number' => 'LIC-1'])->save();
        $this->actingAs($this->admin());
        $nuevoCorreo = 'ana-nueva-' . uniqid() . '@example.com';

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirEditar', 'medico', $r['medico']->id)
            ->assertSet('modal', 'editar')
            ->assertSet('name', 'Ana')
            ->assertSet('lastname', 'Vieja')
            ->assertSet('email', $r['medico']->email)
            ->assertSet('phone', '0212-1111111')
            ->assertSet('license_number', 'LIC-1')
            ->assertSet('reg_medico', $r['medico']->reg_medico)
            ->set('name', 'Ana María')->set('lastname', 'Nueva')->set('email', strtoupper($nuevoCorreo))
            ->set('phone', '')->set('license_number', 'LIC-2')
            ->call('guardarEdicion')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $m = $r['medico']->fresh();
        $this->assertSame('Ana María', $m->name);
        $this->assertSame('Nueva', $m->lastname);
        $this->assertSame($nuevoCorreo, $m->email, 'El correo se guarda en minúsculas.');
        $this->assertNull($m->phone, 'Un campo vaciado queda en null.');
        $this->assertSame('LIC-2', $m->license_number);
        // El reg_medico (llave de sus datos en la nube) no cambia.
        $this->assertSame($r['medico']->reg_medico, $m->reg_medico);
        // La cuenta de acceso sigue a la ficha: nombre y correo de inicio de sesión.
        $this->assertSame('Ana María Nueva', $r['user']->fresh()->name);
        $this->assertSame($nuevoCorreo, $r['user']->fresh()->email);
    }

    public function test_editar_no_deja_repetir_correo_ni_dejar_campos_obligatorios_vacios(): void
    {
        $a = $this->cuentas->crearMedico(['name' => 'A', 'lastname' => 'A', 'email' => 'a-' . uniqid() . '@example.com', 'reg_medico' => 'test-a-' . uniqid()]);
        $b = $this->cuentas->crearMedico(['name' => 'B', 'lastname' => 'B', 'email' => 'b-' . uniqid() . '@example.com', 'reg_medico' => 'test-b-' . uniqid()]);
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('abrirEditar', 'medico', $a['medico']->id)
            ->set('email', $b['user']->email)
            ->call('guardarEdicion')
            ->assertHasErrors(['email'])
            ->assertSet('modal', 'editar')
            ->set('email', $a['user']->email)->set('name', '')->set('lastname', '')
            ->call('guardarEdicion')
            ->assertHasErrors(['name', 'lastname']);

        // Dejar su propio correo tal cual NO es "repetido".
        Livewire::test(Cuentas::class)
            ->call('abrirEditar', 'medico', $a['medico']->id)
            ->set('name', 'A2')
            ->call('guardarEdicion')
            ->assertHasNoErrors();
        $this->assertSame('A2', $a['medico']->fresh()->name);
    }

    public function test_editar_un_medico_sin_cuenta_solo_toca_la_ficha(): void
    {
        $medico = Medico::create([
            'name' => 'Sin', 'lastname' => 'Cuenta', 'email' => 'sc-' . uniqid() . '@example.com', 'reg_medico' => 'test-sc-' . uniqid(),
        ]);
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('abrirEditar', 'medico', $medico->id)
            ->set('name', 'Con')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertSame('Con', $medico->fresh()->name);
        $this->assertNull($medico->fresh()->user_id);
    }

    public function test_editar_administrador_cambia_nombre_y_correo_tambien_en_su_ficha_de_medico(): void
    {
        $r = $this->cuentas->crearMedico(['name' => 'Doble', 'lastname' => 'Rol', 'email' => 'doble-' . uniqid() . '@example.com', 'reg_medico' => 'test-d-' . uniqid()]);
        $this->cuentas->hacerAdministrador($r['user']);
        $this->actingAs($this->admin());
        $nuevo = 'doble-nuevo-' . uniqid() . '@example.com';

        Livewire::test(Cuentas::class)
            ->set('pestana', 'administradores')
            ->call('abrirEditar', 'administrador', $r['user']->id)
            ->assertSet('name', 'Doble Rol')
            ->assertSet('email', $r['user']->email)
            ->set('name', 'Doble R.')->set('email', $nuevo)
            ->call('guardarEdicion')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $this->assertSame('Doble R.', $r['user']->fresh()->name);
        $this->assertSame($nuevo, $r['user']->fresh()->email);
        $this->assertSame($nuevo, $r['medico']->fresh()->email, 'El login del legado usa el correo de la ficha: se mantiene igual.');
    }

    /* ------------------------------------------------------------------ */
    /* Roles                                                               */
    /* ------------------------------------------------------------------ */

    public function test_la_ventana_de_roles_lista_todos_y_marca_los_actuales(): void
    {
        $medico = $this->medicoSinPermisos();
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('abrirRoles', $medico->id)
            ->assertSet('modal', 'roles')
            ->assertSet('rolesSeleccionados', ['Medico'])
            ->assertSee('Root')
            ->assertSee('Administrador')
            ->assertSee('Medico')
            ->assertSee('Secretaria')
            ->assertSee('Paciente')
            ->assertSee('Representante');
    }

    public function test_agregar_y_quitar_roles_deja_exactamente_los_marcados(): void
    {
        $medico = $this->medicoSinPermisos();
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('abrirRoles', $medico->id)
            ->set('rolesSeleccionados', ['Medico', 'Administrador', 'Secretaria'])
            ->call('guardarRoles')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertEqualsCanonicalizing(['Medico', 'Administrador', 'Secretaria'], $medico->fresh()->getRoleNames()->all());
        $this->assertTrue($medico->fresh()->esAdministrador());

        // Se quitan dos y se conserva uno.
        Livewire::test(Cuentas::class)
            ->call('abrirRoles', $medico->id)
            ->set('rolesSeleccionados', ['Medico'])
            ->call('guardarRoles')
            ->assertHasNoErrors();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(['Medico'], $medico->fresh()->getRoleNames()->all());
        $this->assertFalse($medico->fresh()->esAdministrador());
    }

    public function test_un_administrador_no_puede_dar_ni_quitar_el_rol_root_pero_un_root_si(): void
    {
        $objetivo = $this->medicoSinPermisos();

        $this->actingAs($this->admin()); // Administrador, no Root
        Livewire::test(Cuentas::class)
            ->call('abrirRoles', $objetivo->id)
            ->set('rolesSeleccionados', ['Medico', 'Root'])
            ->call('guardarRoles')
            ->assertHasErrors(['roles'])
            ->assertSet('modal', 'roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($objetivo->fresh()->hasRole('Root'));

        // Un Root sí.
        $this->app['auth']->forgetGuards();
        $root = $this->admin();
        $root->syncRoles(['Root']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($root->fresh());
        Livewire::test(Cuentas::class)
            ->call('abrirRoles', $objetivo->id)
            ->set('rolesSeleccionados', ['Medico', 'Root'])
            ->call('guardarRoles')
            ->assertHasNoErrors();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($objetivo->fresh()->hasRole('Root'));
    }

    public function test_los_roles_no_dejan_quitarse_el_propio_acceso_ni_dejar_sin_administradores_ni_inventar_roles(): void
    {
        User::administradores()->update(['is_active' => false]);
        $admin = $this->admin();
        $this->actingAs($admin);

        // A sí mismo.
        Livewire::test(Cuentas::class)
            ->call('abrirRoles', $admin->id)
            ->set('rolesSeleccionados', ['Medico'])
            ->call('guardarRoles')
            ->assertHasErrors(['roles'])
            ->assertSet('modal', 'roles');
        $this->assertTrue($admin->fresh()->esAdministrador());

        // Un rol que no existe.
        Livewire::test(Cuentas::class)
            ->call('abrirRoles', $admin->id)
            ->set('rolesSeleccionados', ['Administrador', 'SuperPoder'])
            ->call('guardarRoles')
            ->assertHasErrors(['roles']);
    }

    /* ------------------------------------------------------------------ */
    /* Generar API key desde el menú                                       */
    /* ------------------------------------------------------------------ */

    public function test_generar_api_key_desde_la_fila_del_medico(): void
    {
        $r = $this->cuentas->crearMedico(['name' => 'Key', 'lastname' => 'Menu', 'email' => 'km-' . uniqid() . '@example.com', 'reg_medico' => 'test-km-' . uniqid()]);
        $this->actingAs($this->admin());

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirEmitir', $r['medico']->id)
            ->assertSet('modal', 'emitir')
            ->assertSee('Generar API key')
            ->set('equipo', 'CONSULTORIO-MENU')
            ->call('emitir')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $mostrada = $panel->get('tokenGenerado');
        $this->assertStringStartsWith('ddr_sync_', $mostrada['token']);
        $this->assertSame($r['medico']->reg_medico, $mostrada['reg_medico']);
        $this->assertSame(1, SyncCredential::where('reg_medico', $r['medico']->reg_medico)->count());

        // El aviso con la clave se muestra una sola vez.
        $panel->assertSee('API key generada')->call('cerrarToken')->assertSet('tokenGenerado', null);
    }

    public function test_generar_api_key_desde_la_fila_de_un_administrador_que_tambien_es_medico(): void
    {
        $r = $this->cuentas->crearMedico(['name' => 'Adm', 'lastname' => 'Med', 'email' => 'am-' . uniqid() . '@example.com', 'reg_medico' => 'test-am-' . uniqid()]);
        $this->cuentas->hacerAdministrador($r['user']);
        $this->actingAs($this->admin());

        $panel = Livewire::test(Cuentas::class)
            ->set('pestana', 'administradores')
            ->set('search', $r['user']->email)
            ->assertSee('Generar API key');

        $panel->call('abrirEmitir', $r['medico']->id)->set('equipo', 'EQ-ADM')->call('emitir')->assertHasNoErrors();
        $this->assertSame(1, SyncCredential::where('reg_medico', $r['medico']->reg_medico)->count());
    }

    public function test_un_administrador_sin_ficha_de_medico_no_ofrece_api_key(): void
    {
        $solo = $this->admin();
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->set('pestana', 'administradores')
            ->set('search', $solo->email)
            ->assertSee('Roles')
            ->assertDontSee('Generar API key');
    }

    /* ------------------------------------------------------------------ */
    /* Cerrar sesión pide confirmación                                     */
    /* ------------------------------------------------------------------ */

    public function test_cerrar_sesion_pide_confirmacion_en_el_panel_lateral_y_en_el_menu_del_usuario(): void
    {
        $this->actingAs($this->admin());

        $pagina = $this->get('/admin/cuentas')->assertOk();

        // La función que muestra la confirmación está en el layout...
        $pagina->assertSee('function confirmarCierreSesion', false);
        // ...y los dos controles de "Cerrar sesión" la usan, en vez de enviar el formulario directo.
        $pagina->assertSee("onclick=\"return confirmarCierreSesion(event, document.getElementById('logout-form'));\"", false);
        $pagina->assertSee('onsubmit="return confirmarCierreSesion(event, this);"', false);
        $pagina->assertDontSee("document.getElementById('logout-form').submit();\">", false);
    }

    public function test_el_prefijo_se_escribe_libre_y_se_guarda_con_punto(): void
    {
        $this->actingAs($this->admin());
        $sufijo = uniqid();

        // Alta: "Dra" -> "Dra."
        Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'medico')
            ->set('prefix', ' Dra ')->set('name', 'Con')->set('lastname', 'Prefijo')
            ->set('email', "pref-{$sufijo}@example.com")->set('reg_medico', "test-pref-{$sufijo}")
            ->call('guardarAlta')
            ->assertHasNoErrors();
        $medico = Medico::where('reg_medico', "test-pref-{$sufijo}")->firstOrFail();
        $this->assertSame('Dra.', $medico->prefix);

        // Edición: el formulario trae el prefijo, uno que ya termina en punto no se duplica y vaciarlo lo deja en null.
        $panel = Livewire::test(Cuentas::class)
            ->call('abrirEditar', 'medico', $medico->id)
            ->assertSet('prefix', 'Dra.')
            ->set('prefix', 'Ing.')->call('guardarEdicion')->assertHasNoErrors();
        $this->assertSame('Ing.', $medico->fresh()->prefix);

        $panel->call('abrirEditar', 'medico', $medico->id)->set('prefix', '')->call('guardarEdicion')->assertHasNoErrors();
        $this->assertNull($medico->fresh()->prefix);
    }

    public function test_el_prefijo_admite_hasta_20_caracteres(): void
    {
        $this->actingAs($this->admin());
        $sufijo = uniqid();

        Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'medico')
            ->set('prefix', str_repeat('a', 21))->set('name', 'Largo')->set('lastname', 'Prefijo')
            ->set('email', "largo-{$sufijo}@example.com")->set('reg_medico', "test-largo-{$sufijo}")
            ->call('guardarAlta')
            ->assertHasErrors(['prefix']);
        $this->assertNull(Medico::where('reg_medico', "test-largo-{$sufijo}")->first());
    }

    public function test_actualizar_un_medico_sin_mandar_prefijo_no_se_lo_borra(): void
    {
        $r = $this->cuentas->crearMedico([
            'name' => 'Sin', 'lastname' => 'Tocar', 'email' => 'sin-' . uniqid() . '@example.com',
            'reg_medico' => 'test-sintocar-' . uniqid(), 'prefix' => 'Lic',
        ]);
        $this->assertSame('Lic.', $r['medico']->fresh()->prefix);

        $this->cuentas->actualizarMedico($r['medico'], ['name' => 'Sin', 'lastname' => 'Tocar Mas', 'email' => $r['medico']->email]);

        $this->assertSame('Lic.', $r['medico']->fresh()->prefix);
    }

    public function test_normalizar_prefijo(): void
    {
        $this->assertNull(Medico::normalizarPrefijo(null));
        $this->assertNull(Medico::normalizarPrefijo('   '));
        $this->assertSame('Dr.', Medico::normalizarPrefijo('Dr'));
        $this->assertSame('Dr.', Medico::normalizarPrefijo(' Dr. '));
        $this->assertSame('Lic.', Medico::normalizarPrefijo('Lic'));
    }
}
