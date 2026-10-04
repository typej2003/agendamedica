<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\ApiKeys;
use App\Models\Medico;
use App\Models\SyncCredential;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\SyncAuthService;
use App\Services\SyncCredencialService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sección "API Keys" del panel (`/admin/api-keys`, componente Livewire `Admin\ApiKeys`).
 *
 * `DatabaseTransactions` (ver MedicAPI/AGENTS.md): la base sqlite de desarrollo trae datos reales.
 */
class ApiKeysPanelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Keys', 'admin-keys-' . uniqid() . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function medico(): Medico
    {
        $sufijo = uniqid();

        return app(CuentaService::class)->crearMedico([
            'name' => 'Doctor', 'lastname' => 'Keys', 'email' => "keys-{$sufijo}@example.com", 'reg_medico' => "test-keys-{$sufijo}",
        ])['medico'];
    }

    /** ¿Esta API key autentica de verdad en los endpoints de sync, y para qué médico? */
    private function autenticaComo(string $token): array
    {
        $peticion = Request::create('/api/sync/carga-inicial/iniciar', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        $r = app(SyncAuthService::class)->validar($peticion);

        return [$r['status'], $r['reg_medico']];
    }

    public function test_acceso_solo_para_administradores_y_enlace_en_el_panel(): void
    {
        $this->get('/admin/api-keys')->assertRedirect('/login');

        $comun = app(CuentaService::class)->crearMedico([
            'name' => 'Comun', 'lastname' => 'Doc', 'email' => 'comun-' . uniqid() . '@example.com', 'reg_medico' => 'test-c-' . uniqid(),
        ])['user'];
        $comun->forceFill(['must_change_password' => false])->save();
        $this->actingAs($comun)->get('/admin/api-keys')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin());
        $this->get('/admin/api-keys')->assertOk()->assertSee('API Keys');
        Livewire::test('layouts.aside')->assertSee(route('admin.api-keys'));
    }

    public function test_lista_los_medicos_con_su_resumen(): void
    {
        $medico = $this->medico();
        app(SyncCredencialService::class)->emitir($medico->reg_medico, 'EQUIPO-LISTA');
        $this->actingAs($this->admin());

        Livewire::test(ApiKeys::class)
            ->set('search', $medico->reg_medico)
            ->assertSee('Doctor Keys')
            ->assertSee('Sin carga')
            ->assertSee('Nunca')
            ->assertSee('Generar API key');
    }

    public function test_generar_una_api_key_la_muestra_una_vez_y_autentica(): void
    {
        $medico = $this->medico();
        $this->actingAs($this->admin());

        $panel = Livewire::test(ApiKeys::class)
            ->call('abrirEmitir', $medico->id)
            ->assertSet('modal', 'emitir')
            ->set('equipo', 'CONSULTORIO-PRUEBA')
            ->call('emitir')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $mostrada = $panel->get('tokenGenerado');
        $this->assertStringStartsWith('ddr_sync_', $mostrada['token']);
        $this->assertSame('CONSULTORIO-PRUEBA', $mostrada['equipo']);

        // Es una credencial real: autentica en el sync y SOLO para ese médico.
        $this->assertSame([SyncAuthService::OK, $medico->reg_medico], $this->autenticaComo($mostrada['token']));

        // En la base solo queda el hash, nunca el token.
        $cred = SyncCredential::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame(SyncCredential::hashToken($mostrada['token']), $cred->token_hash);
        $this->assertNotSame($mostrada['token'], $cred->token_hash);

        $panel->call('cerrarToken')->assertSet('tokenGenerado', null);
    }

    public function test_un_equipo_con_api_key_activa_pide_confirmar_el_reemplazo(): void
    {
        $medico = $this->medico();
        $primera = app(SyncCredencialService::class)->emitir($medico->reg_medico, 'CONSULTORIO-1');
        $this->actingAs($this->admin());

        $panel = Livewire::test(ApiKeys::class)
            ->call('abrirEmitir', $medico->id)
            ->set('equipo', 'CONSULTORIO-1')
            ->call('emitir')
            ->assertHasErrors(['equipo'])
            ->assertSet('mostrarReemplazo', true)
            ->assertSet('modal', 'emitir');

        $this->assertNull($panel->get('tokenGenerado'));
        $this->assertTrue($primera['credencial']->fresh()->estaActiva(), 'Sin confirmar no se revoca nada.');

        $panel->set('reemplazar', true)->call('emitir')->assertHasNoErrors();

        $nuevo = $panel->get('tokenGenerado');
        $this->assertSame(1, $nuevo['revocadas']);
        $this->assertTrue($primera['credencial']->fresh()->estaRevocada());
        $this->assertSame([SyncAuthService::OK, $medico->reg_medico], $this->autenticaComo($nuevo['token']));
        $this->assertSame(SyncAuthService::INVALIDA, $this->autenticaComo($primera['token'])[0]);
    }

    public function test_el_nombre_del_equipo_es_obligatorio(): void
    {
        $medico = $this->medico();
        $this->actingAs($this->admin());

        Livewire::test(ApiKeys::class)
            ->call('abrirEmitir', $medico->id)
            ->set('equipo', '')
            ->call('emitir')
            ->assertHasErrors(['equipo'])
            ->assertSet('modal', 'emitir');

        $this->assertSame(0, SyncCredential::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_ver_y_revocar_credenciales(): void
    {
        $medico = $this->medico();
        $a = app(SyncCredencialService::class)->emitir($medico->reg_medico, 'EQUIPO-VER');
        $this->actingAs($this->admin());

        Livewire::test(ApiKeys::class)
            ->call('verCredenciales', $medico->id)
            ->assertSet('modal', 'credenciales')
            ->assertSee('EQUIPO-VER')
            ->assertSee('Activa')
            ->call('revocar', $a['credencial']->id)
            ->assertSee('Revocada');

        $this->assertSame(SyncAuthService::INVALIDA, $this->autenticaComo($a['token'])[0]);
    }

    public function test_las_acciones_tambien_se_comprueban_en_cada_peticion(): void
    {
        $medico = $this->medico();
        $admin = $this->admin();
        $this->actingAs($admin);

        $panel = Livewire::test(ApiKeys::class)->call('abrirEmitir', $medico->id)->set('equipo', 'COLADO');

        // Le quitan el rol a mitad de sesión: la acción siguiente no puede surtir efecto.
        $admin->removeRole('Administrador');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin->fresh());

        $panel->call('emitir');

        $this->assertSame(0, SyncCredential::where('reg_medico', $medico->reg_medico)->count(), 'Una acción sin permiso no debe emitir nada.');
        $this->assertNull($panel->get('tokenGenerado'));
    }
}
