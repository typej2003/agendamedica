<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Pagos;
use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Pagos** del panel (`/admin/pagos`, 2026-10-21): lo que entró por SyPago, con su bitácora y la
 * conciliación manual.
 *
 * Lo que se fija: sólo entra un administrador; la lista filtra por estado; el detalle muestra los eventos
 * (sin secretos); y "Consultar" reconsulta a SyPago y aplica el resultado, igual que el webhook y el sondeo.
 */
class PagosAdminTest extends TestCase
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
            'email' => 'admin-pagos-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function pago(array $datos = []): Pago
    {
        return Pago::create($datos + [
            'reg_medico' => 'pago-admin-' . substr(uniqid(), -6),
            'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
            'canal' => Pago::CANAL_PLAN,
            'pagable_tipo' => Pago::PAGABLE_PLAN,
            'concepto' => 'Plan de prueba',
            'moneda' => 'VES',
            'monto_e2' => 73000,
            'monto_usd_e2' => 2000,
            'tasa' => 36.5,
            'neto_usd_e2' => 2000,
            'neto_ves_e2' => 73000,
            'estado' => Pago::PENDIENTE,
            'expira_el' => now()->addMinutes(15),
        ]);
    }

    private function metodoConfigurado(): MetodoPago
    {
        $cuenta = CuentaBancaria::create([
            'propietario' => CuentaBancaria::PLATAFORMA,
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::CUENTA,
            'banco_codigo' => '0172',
            'numero' => '01340123456789012345',
            'titular' => 'Doctorisimo, C.A.',
            'documento_tipo' => 'J',
            'documento_numero' => 'J-12345678-9',
            'activa' => true,
        ]);

        return MetodoPago::create([
            'propietario' => MetodoPago::PLATAFORMA,
            'pasarela' => 'sypago',
            'variante' => 'debito_inmediato',
            'nombre' => 'SyPago · Débito inmediato',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'credenciales' => ['access_token' => 'token-de-prueba', 'host' => 'https://app.sypago.net:8086/api/v1/'],
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::AMBOS,
            'orden' => 1,
        ]);
    }

    public function test_acceso_solo_para_administradores_y_enlace_en_el_panel(): void
    {
        $this->get('/admin/pagos')->assertRedirect('/login');

        $medico = $this->usuario('Medico');
        $this->actingAs($medico)->get('/admin/pagos')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->usuario('Root'));
        $this->get('/admin/pagos')->assertOk()->assertSee('Pagos');
        Livewire::test('layouts.aside')->assertSee(route('admin.pagos'));
    }

    public function test_lista_los_pagos_y_filtra_por_estado(): void
    {
        $this->pago(['concepto' => 'Plan aprobado de prueba', 'estado' => Pago::APROBADO]);
        $this->pago(['concepto' => 'Plan pendiente de prueba', 'estado' => Pago::PENDIENTE]);

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(Pagos::class)
            ->assertSee('Plan aprobado de prueba')
            ->assertSee('Plan pendiente de prueba')
            ->set('estado', Pago::APROBADO)
            ->assertSee('Plan aprobado de prueba')
            ->assertDontSee('Plan pendiente de prueba');
    }

    public function test_el_detalle_muestra_la_bitacora_sin_secretos(): void
    {
        $pago = $this->pago([
            'estado' => Pago::APROBADO,
            'operation_secret' => 'secreto-de-la-operacion',
            'respuesta' => ['status' => 'ACCP', 'operation_secret' => 'secreto-de-la-operacion'],
        ]);
        $pago->registrarEvento('webhook', 'aprobado', ['status' => 'ACCP'], Pago::EN_PROCESO);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Pagos::class)
            ->call('abrir', $pago->id)
            ->assertSee('Bitácora')
            ->assertSee('webhook')
            ->assertSee('aprobado')
            ->assertDontSee('secreto-de-la-operacion');
    }

    public function test_consultar_reconsulta_y_aprueba_el_pago(): void
    {
        Mail::fake();
        Http::fake([
            '*transaction/tx-admin*' => Http::response(['status' => 'ACCP', 'transaction_id' => 'tx-admin'], 200),
        ]);

        $plan = Plan::create([
            'codigo' => 'admin-' . substr(uniqid(), -6),
            'nombre' => 'Plan del panel',
            'frecuencia' => Plan::MENSUAL,
            'precio_usd' => 20,
            'es_default' => false,
            'visible' => true,
            'activo' => true,
            'orden' => 20,
        ]);
        $metodo = $this->metodoConfigurado();
        $pago = $this->pago([
            'pagable_id' => $plan->id,
            'metodo_pago_id' => $metodo->id,
            'cuenta_bancaria_id' => $metodo->cuenta_bancaria_id,
            'estado' => Pago::EN_PROCESO,
            'referencia_externa' => 'tx-admin',
            'otp_enviado_el' => now(),
        ]);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Pagos::class)->call('consultar', $pago->id);

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $pago->reg_medico)
            ->where('origen', RegMedicoServicio::ORIGEN_COMPRA)->count());
    }
}
