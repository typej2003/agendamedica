<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\MetodosPago;
use App\Models\MetodoPago;
use App\Models\TasaDeCambio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Métodos de pago de la plataforma** (`/admin/metodos-pago`): configurar SyPago y la tasa VES/USD.
 *
 * Lo que se fija:
 *  - sólo entra un administrador (el médico y la secretaría reciben 403);
 *  - las credenciales se guardan **cifradas** y el token real no queda en la columna;
 *  - editar sin volver a escribir el token lo conserva;
 *  - la tasa se carga con historial y no se acepta cero;
 *  - "Probar" habla con SyPago (`GET banks`) y deja el resultado.
 *
 * `DatabaseTransactions` (ver MedicAPI/AGENTS.md): la base sqlite de desarrollo trae datos reales.
 */
class MetodosPagoAdminTest extends TestCase
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
            'name' => 'Usuario de pagos',
            'email' => 'pagos-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function administrador(): User
    {
        return $this->usuario('Root');
    }

    /** Los campos mínimos de un SyPago de débito inmediato con su cuenta. */
    private function datosDelMetodo(array $cambios = []): array
    {
        return $cambios + [
            'pasarela' => 'sypago',
            'variante' => 'debito_inmediato',
            'nombre' => 'SyPago · Débito inmediato',
            'entorno' => 'produccion',
            'disponiblePara' => 'ambos',
            'orden' => 1,
            'comisionPorcentaje' => '10',
            'host' => 'https://app.sypago.net:8086/api/v1/',
            'accessToken' => 'token-secreto-1234',
            'bancoCodigo' => '0172',
            'cuentaNumero' => '01340123456789012345',
            'cuentaTitular' => 'Doctorisimo, C.A.',
            'cuentaDocumentoTipo' => 'J',
            'cuentaDocumentoNumero' => 'J-12345678-9',
        ];
    }

    public function test_acceso_solo_para_administradores_y_enlace_en_el_panel(): void
    {
        $this->get('/admin/metodos-pago')->assertRedirect('/login');

        $medico = $this->usuario('Medico');
        $this->actingAs($medico)->get('/admin/metodos-pago')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->administrador());
        $this->get('/admin/metodos-pago')->assertOk()->assertSee('Métodos de pago');
        Livewire::test('layouts.aside')->assertSee(route('admin.metodos-pago'));
    }

    public function test_configura_sypago_con_credenciales_cifradas_y_su_cuenta(): void
    {
        $this->actingAs($this->administrador());

        $panel = Livewire::test(MetodosPago::class);
        foreach ($this->datosDelMetodo() as $campo => $valor) {
            $panel->set($campo, $valor);
        }
        $panel->call('guardarMetodo')->assertHasNoErrors()->assertSet('modal', null);

        $metodo = MetodoPago::deLaPlataforma()->latest('id')->firstOrFail();

        $this->assertSame('SyPago · Débito inmediato', $metodo->nombre);
        $this->assertSame(1000, $metodo->comision_bp); // 10,00% → 1000 puntos base
        $this->assertNotNull($metodo->webhook_token);
        $this->assertSame(64, strlen($metodo->webhook_token));
        $this->assertTrue($metodo->estaConfigurado());

        // El modelo lo descifra…
        $this->assertSame('token-secreto-1234', $metodo->credenciales['access_token']);

        // …y en la columna no está el token en claro.
        $crudo = DB::table('metodos_pago')->where('id', $metodo->id)->value('credenciales');
        $this->assertIsString($crudo);
        $this->assertStringNotContainsString('token-secreto-1234', $crudo);

        // La cuenta quedó vinculada y completa.
        $this->assertSame('0172', $metodo->cuentaBancaria->banco_codigo);
        $this->assertSame('Doctorisimo, C.A.', $metodo->cuentaBancaria->titular);
        $this->assertTrue($metodo->cuentaBancaria->estaCompleta());
    }

    public function test_editar_sin_token_conserva_el_token_guardado(): void
    {
        $this->actingAs($this->administrador());

        $panel = Livewire::test(MetodosPago::class);
        foreach ($this->datosDelMetodo() as $campo => $valor) {
            $panel->set($campo, $valor);
        }
        $panel->call('guardarMetodo')->assertHasNoErrors();

        $metodo = MetodoPago::deLaPlataforma()->latest('id')->firstOrFail();

        Livewire::test(MetodosPago::class)
            ->call('editarMetodo', $metodo->id)
            ->assertSet('accessToken', '')
            ->set('nombre', 'SyPago · Débito inmediato (editado)')
            ->call('guardarMetodo')
            ->assertHasNoErrors();

        $metodo->refresh();
        $this->assertSame('SyPago · Débito inmediato (editado)', $metodo->nombre);
        $this->assertSame('token-secreto-1234', $metodo->credenciales['access_token']);
    }

    public function test_la_tasa_se_carga_con_historial_y_queda_vigente(): void
    {
        $this->actingAs($this->administrador());

        Livewire::test(MetodosPago::class)
            ->call('nuevaTasa')
            ->set('tasa', '36,5')
            ->call('guardarTasa')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $tasa = TasaDeCambio::actual();

        $this->assertNotNull($tasa);
        $this->assertSame('36.500000', (string) $tasa->tasa);
        $this->assertSame('36,5000', $tasa->aTexto());
        $this->assertSame(365_000, $tasa->convertirUsdAVes(10_000));
    }

    public function test_una_tasa_cero_no_se_acepta(): void
    {
        $this->actingAs($this->administrador());

        Livewire::test(MetodosPago::class)
            ->call('nuevaTasa')
            ->set('tasa', '0')
            ->call('guardarTasa')
            ->assertHasErrors('tasa');
    }

    public function test_probar_conexion_trae_los_bancos_de_sypago(): void
    {
        Http::fake([
            '*' => Http::response([
                ['Code' => '0172', 'Name' => 'Bancamiga', 'Active' => true, 'IsDebitOTP' => true],
                ['Code' => '0102', 'Name' => 'Banco de Venezuela', 'Active' => true, 'IsDebitOTP' => false],
            ], 200),
        ]);

        $this->actingAs($this->administrador());

        $panel = Livewire::test(MetodosPago::class);
        foreach ($this->datosDelMetodo() as $campo => $valor) {
            $panel->set($campo, $valor);
        }
        $panel->call('guardarMetodo')->assertHasNoErrors();

        $metodo = MetodoPago::deLaPlataforma()->latest('id')->firstOrFail();

        $panel->call('probarConexion', $metodo->id)
            ->assertSet('resultadoPrueba.ok', true);

        $this->assertCount(2, $panel->get('bancos'));
        $this->assertSame('0172', $panel->get('bancos')[0]['codigo']);
        $this->assertNotNull($metodo->fresh()->verificado_el);
    }
}
