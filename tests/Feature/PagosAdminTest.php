<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Pagos;
use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
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

    // ------------------------------------------------------------------ Administrar un pago (O-53)

    /** Un médico con su registro y una cita suya, que es lo que administra el panel. */
    private function citaDeUnMedico(array $extra = []): array
    {
        $reg = 'admin-cita-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dr. de prueba', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Cobros',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        $cita = Cola::create(array_merge([
            'reg_medico' => $reg,
            'fecha' => '2026-11-10',
            'hora_ini' => '08:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'monto' => 50,
            'monto_pagado' => 0,
        ], $extra));

        return [$medico, $cita];
    }

    private function pagoDeCita(Cola $cita, array $datos = []): Pago
    {
        return $this->pago($datos + [
            'reg_medico' => $cita->reg_medico,
            'beneficiario_tipo' => Pago::BENEFICIARIO_MEDICO,
            'beneficiario_reg_medico' => $cita->reg_medico,
            'canal' => Pago::CANAL_MOSTRADOR,
            'pagable_tipo' => Pago::PAGABLE_CITA,
            'pagable_id' => $cita->id,
            'concepto' => 'Consulta',
            'moneda' => 'USD',
            'monto_e2' => 5000,
            'monto_usd_e2' => 5000,
            'estado' => Pago::PENDIENTE,
        ]);
    }

    public function test_lo_pendiente_se_agrupa_por_medico_y_no_cuenta_lo_aprobado(): void
    {
        [$medico, $cita] = $this->citaDeUnMedico();
        $this->pagoDeCita($cita, ['estado' => Pago::PENDIENTE]);
        $this->pagoDeCita($cita, ['estado' => Pago::EN_PROCESO, 'monto_usd_e2' => 2500, 'monto_e2' => 2500]);
        $this->pagoDeCita($cita, ['estado' => Pago::APROBADO]);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Pagos::class)
            ->assertSee('Pendiente de cobro por médico')
            ->assertSee($medico->reg_medico)
            // Dos pagos abiertos por 75,00 USD: lo aprobado no entra.
            ->assertSee('75,00');
    }

    public function test_marcar_pagado_aprueba_suma_a_la_cita_y_deja_su_evento(): void
    {
        [$medico, $cita] = $this->citaDeUnMedico();
        $pago = $this->pagoDeCita($cita);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Pagos::class)
            ->call('administrar', $pago->id, 'aprobar')
            ->set('motivo', 'Transferencia verificada')
            ->call('aplicarAccion')
            ->assertHasNoErrors();

        $pago->refresh();
        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertNotNull($pago->pagado_el);

        $evento = $pago->eventos()->where('evento', 'aprobado_manualmente')->first();
        $this->assertNotNull($evento);
        $this->assertSame(Pago::PENDIENTE, $evento->estado_anterior);
        $this->assertSame(PagoEvento::ORIGEN_MANUAL, $evento->origen);

        // La cita queda pagada por el monto del pago, como cuando aprueba la pasarela.
        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
    }

    public function test_rechazar_pide_motivo_y_deja_la_bitacora(): void
    {
        [, $cita] = $this->citaDeUnMedico();
        $pago = $this->pagoDeCita($cita);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Pagos::class)
            ->call('administrar', $pago->id, 'rechazar')
            ->call('aplicarAccion')
            ->assertHasErrors('motivo');

        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado);

        Livewire::test(Pagos::class)
            ->call('administrar', $pago->id, 'rechazar')
            ->set('motivo', 'El paciente nunca transfirió')
            ->call('aplicarAccion')
            ->assertHasNoErrors();

        $this->assertSame(Pago::RECHAZADO, $pago->fresh()->estado);
        $this->assertSame(
            'El paciente nunca transfirió',
            $pago->eventos()->where('evento', 'rechazado_manualmente')->first()?->payload['motivo'] ?? null,
        );
    }

    public function test_reembolsar_devuelve_la_plata_y_solo_desde_aprobado(): void
    {
        [, $cita] = $this->citaDeUnMedico();
        $pago = $this->pagoDeCita($cita, ['estado' => Pago::PENDIENTE]);

        $this->actingAs($this->usuario('Root'));

        // Un pago pendiente no se reembolsa.
        Livewire::test(Pagos::class)
            ->call('administrar', $pago->id, 'reembolsar')
            ->assertHasErrors('motivo');

        // Aprobado, sí: baja el monto pagado de la cita y no se puede reembolsar dos veces.
        $pago->forceFill(['estado' => Pago::APROBADO, 'pagado_el' => now()])->save();
        $cita->forceFill(['monto_pagado' => 50])->save();

        Livewire::test(Pagos::class)
            ->call('administrar', $pago->id, 'reembolsar')
            ->set('motivo', 'Se canceló la consulta')
            ->call('aplicarAccion')
            ->assertHasNoErrors();

        $this->assertSame(Pago::REEMBOLSADO, $pago->fresh()->estado);
        $this->assertSame(0.0, (float) $cita->fresh()->monto_pagado);

        Livewire::test(Pagos::class)
            ->call('administrar', $pago->id, 'reembolsar')
            ->assertHasErrors('motivo');
    }

    public function test_un_pago_de_plan_no_se_administra_desde_el_panel(): void
    {
        $pago = $this->pago(['estado' => Pago::PENDIENTE]);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Pagos::class)
            ->call('administrar', $pago->id, 'aprobar')
            ->assertHasErrors('motivo');

        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado);
    }
}
