<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoTransaccion;
use App\Models\PreferenciaDeCobro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **Cobros** (`/clinica/cobros`): la pantalla donde el médico carga sus cuentas de pago móvil, elige quién
 * recauda y confirma —o rechaza— los pagos que el banco no confirmó solo.
 *
 * Lo que se fija acá: guardar una cuenta crea su método de pago; la preferencia no se puede encender sin
 * una cuenta; el médico y la secretaría confirman, pero las cuentas sólo las ve el médico; y un pago de
 * otro consultorio es 404.
 */
class CobrosDePagoMovilWebTest extends TestCase
{
    use DatabaseTransactions;

    private Medico $medico;

    private Specialty $gineco;

    private User $user;

    private string $reg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EspecialidadesYModulosSeeder::class);

        $this->reg = 'cobros-' . substr(uniqid(), -6);

        $this->user = User::create([
            'name' => 'Doctora de cobros', 'email' => "{$this->reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $this->user->assignRole('Medico');

        $this->medico = Medico::create([
            'user_id' => $this->user->id, 'name' => 'Doctora', 'lastname' => 'De Cobros',
            'email' => "{$this->reg}@example.com", 'password' => $this->user->password, 'reg_medico' => $this->reg,
        ]);

        $this->gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $this->medico->specialties()->attach($this->gineco->id);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->reg]);

        config([
            'pagos.pago_movil.bancaribe.token_url' => 'https://banco.prueba/oauth2/token',
            'pagos.pago_movil.bancaribe.consulta_url' => 'https://banco.prueba/queryPaymentB2P',
            'pagos.pago_movil.bancaribe.verificar_certificado' => false,
        ]);

        Cache::flush();
    }

    private function conContexto(?User $user = null): self
    {
        if ($user !== null) {
            $this->actingAs($user);
        } else {
            $this->actingAs($this->user);
        }

        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $this->medico->reg_medico,
            'specialty_id' => $this->gineco->id,
            'office_id' => null,
        ]]);
    }

    /** @return array<string,string> */
    private function datosDeCuenta(array $extra = []): array
    {
        return array_merge([
            'banco_codigo' => '0114',
            'telefono' => '04141234567',
            'numero' => '01140000000000000000',
            'titular' => 'Consultorio de prueba',
            'documento_tipo' => 'J',
            'documento_numero' => '302597005',
            'conciliacion' => CuentaBancaria::CONCILIACION_MANUAL,
        ], $extra);
    }

    private function cuentaDelMedico(): CuentaBancaria
    {
        return CuentaBancaria::create([
            'propietario' => CuentaBancaria::MEDICO,
            'reg_medico' => $this->reg,
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::PAGO_MOVIL,
            'banco_codigo' => '0114',
            'telefono' => '04141234567',
            'numero' => '01140000000000000000',
            'titular' => 'Consultorio de prueba',
            'documento_tipo' => 'J',
            'documento_numero' => '302597005',
            'conciliacion' => CuentaBancaria::CONCILIACION_MANUAL,
            'activa' => true,
        ]);
    }

    private function metodoDeLaCuenta(CuentaBancaria $cuenta): MetodoPago
    {
        return MetodoPago::create([
            'propietario' => MetodoPago::MEDICO,
            'reg_medico' => $this->reg,
            'pasarela' => 'pago_movil',
            'variante' => 'pago_movil',
            'nombre' => 'Pago móvil · Bancaribe',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::COBROS,
            'orden' => 1,
        ]);
    }

    private function pagoPendiente(): Pago
    {
        $cita = Cola::create([
            'reg_medico' => $this->reg,
            'fecha' => '2026-11-10',
            'hora_ini' => '09:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'monto' => null,
            'monto_pagado' => null,
        ]);

        $cuenta = $this->cuentaDelMedico();
        $metodo = $this->metodoDeLaCuenta($cuenta);

        $pago = Pago::create([
            'reg_medico' => $this->reg,
            'beneficiario_tipo' => Pago::BENEFICIARIO_MEDICO,
            'beneficiario_reg_medico' => $this->reg,
            'recaudador' => Pago::RECAUDADOR_MEDICO,
            'canal' => Pago::CANAL_LINK,
            'pagable_tipo' => Pago::PAGABLE_CITA,
            'pagable_id' => $cita->id,
            'concepto' => 'Cita del 10/11/2026 · #' . $cita->id,
            'metodo_pago_id' => $metodo->id,
            'cuenta_bancaria_id' => $cuenta->id,
            'moneda' => 'VES',
            'monto_e2' => 182500,
            'monto_usd_e2' => 5000,
            'estado' => Pago::PENDIENTE,
            'datos_pagador' => [
                'nombre' => 'Paciente de prueba',
                'documento_tipo' => 'V',
                'documento_numero' => '12345678',
                'telefono' => '04141234114',
            ],
        ]);

        $pago->transacciones()->create([
            'cuenta_bancaria_id' => $cuenta->id,
            'referencia' => '000945672279',
            'tipo' => PagoTransaccion::TIPO_PAGO_MOVIL,
            'monto_e2' => 182500,
            'moneda' => 'VES',
            'estado' => PagoTransaccion::PENDIENTE,
            'origen' => PagoTransaccion::ORIGEN_PACIENTE,
        ]);

        return $pago;
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_la_pantalla_carga_para_el_medico(): void
    {
        $this->conContexto()->get(route('clinica.cobros'))
            ->assertOk()
            ->assertSee('Por confirmar')
            ->assertSee('Mis cuentas de pago móvil')
            ->assertSee('¿Quién cobra tus citas?');
    }

    public function test_guardar_una_cuenta_crea_su_metodo_de_pago(): void
    {
        $this->conContexto()->post(route('clinica.cobros.cuentas.guardar'), $this->datosDeCuenta())
            ->assertRedirect(route('clinica.cobros'));

        $cuenta = CuentaBancaria::where('reg_medico', $this->reg)->firstOrFail();
        $this->assertSame('0114', $cuenta->banco_codigo);
        $this->assertTrue($cuenta->predeterminada);

        $metodo = MetodoPago::where('reg_medico', $this->reg)->firstOrFail();
        $this->assertSame('pago_movil', $metodo->pasarela);
        $this->assertSame($cuenta->id, $metodo->cuenta_bancaria_id);
        $this->assertTrue($metodo->estaConfigurado());
    }

    public function test_no_se_puede_guardar_una_cuenta_de_un_banco_sin_api_como_automatica(): void
    {
        $this->conContexto()->post(route('clinica.cobros.cuentas.guardar'), $this->datosDeCuenta([
            'banco_codigo' => '0102',
            'conciliacion' => CuentaBancaria::CONCILIACION_AUTOMATICA,
        ]))->assertSessionHasErrors('conciliacion');

        $this->assertSame(0, CuentaBancaria::where('reg_medico', $this->reg)->count());
    }

    public function test_probar_la_conexion_marca_la_cuenta_verificada(): void
    {
        Http::fake([
            'https://banco.prueba/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        ]);

        $cuenta = $this->cuentaDelMedico();
        $cuenta->update([
            'conciliacion' => CuentaBancaria::CONCILIACION_AUTOMATICA,
            'credenciales' => ['consumer_key' => 'clave', 'consumer_secret' => 'secreto'],
        ]);

        $this->conContexto()->post(route('clinica.cobros.cuentas.probar', $cuenta))
            ->assertRedirect(route('clinica.cobros'));

        $this->assertNotNull($cuenta->fresh()->conciliacion_verificada_el);
    }

    public function test_la_preferencia_por_el_medico_exige_una_cuenta_configurada(): void
    {
        $this->conContexto()->post(route('clinica.cobros.preferencia'), ['recaudador' => 'medico'])
            ->assertSessionHas('error');

        $this->assertSame(PreferenciaDeCobro::PLATAFORMA, PreferenciaDeCobro::de($this->reg));
    }

    public function test_confirmar_a_mano_aprueba_el_pago_y_marca_la_cita(): void
    {
        $pago = $this->pagoPendiente();

        $this->conContexto()->post(route('clinica.cobros.confirmar', $pago), [
            'referencia' => '000945672279',
            'monto' => '1.825,00',
        ])->assertRedirect(route('clinica.cobros'));

        $pago->refresh();
        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertSame($this->user->id, (int) $pago->confirmado_por);
        $this->assertSame(182500, (int) $pago->monto_confirmado_e2);

        $this->assertSame(PagoTransaccion::APROBADA, $pago->transacciones()->firstOrFail()->estado);

        $cita = $pago->cita();
        $this->assertEqualsWithDelta(50.0, (float) $cita->monto_pagado, 0.01);
    }

    /**
     * **La decisión primero** (guía de UI, §11): el pago se resuelve eligiendo «¿Qué haces?» y recién
     * entonces aparece el dato de esa acción (monto o motivo) con su botón.
     */
    public function test_el_pago_pendiente_se_resuelve_eligiendo_la_accion(): void
    {
        $pago = $this->pagoPendiente();

        $html = $this->conContexto()->get(route('clinica.cobros'))->assertOk()->getContent();

        $this->assertStringContainsString('data-cobro-resolver', $html);
        $this->assertStringContainsString('¿Qué haces?', $html);
        // Las dos ramas existen pero arrancan ocultas: las muestra `cobros.js` al elegir.
        $this->assertStringContainsString('data-cobro-campos="confirmar" hidden', $html);
        $this->assertStringContainsString('data-cobro-campos="rechazar" hidden', $html);
        // Cada rama lleva la acción de su botón.
        $this->assertStringContainsString(route('clinica.cobros.confirmar', $pago), $html);
        $this->assertStringContainsString(route('clinica.cobros.rechazar', $pago), $html);
    }

    public function test_rechazar_a_mano_deja_el_pago_rechazado(): void
    {
        $pago = $this->pagoPendiente();

        $this->conContexto()->post(route('clinica.cobros.rechazar', $pago), ['motivo' => 'No llegó'])
            ->assertRedirect(route('clinica.cobros'));

        $this->assertSame(Pago::RECHAZADO, $pago->fresh()->estado);
    }

    public function test_la_secretaria_ve_los_pendientes_pero_no_las_cuentas(): void
    {
        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => "sec-{$this->reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');

        // La secretaría trabaja en el consultorio del médico: el vínculo es la fila de `medico_registros`
        // (la misma que ya existe, ahora con su usuario).
        MedicoRegistro::where('medico_id', $this->medico->id)->update(['user_id' => $secretaria->id]);

        $this->conContexto($secretaria)->get(route('clinica.cobros'))
            ->assertOk()
            ->assertSee('Por confirmar')
            ->assertDontSee('Mis cuentas de pago móvil');
    }

    public function test_un_pago_de_otro_consultorio_es_404(): void
    {
        $pago = $this->pagoPendiente();
        $pago->update(['beneficiario_reg_medico' => 'otro-consultorio']);

        $this->conContexto()->post(route('clinica.cobros.confirmar', $pago), [
            'referencia' => '000945672279',
        ])->assertNotFound();
    }
}
