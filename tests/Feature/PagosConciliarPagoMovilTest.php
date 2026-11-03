<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoTransaccion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **`pagos:conciliar` con pago móvil**: reintenta las declaraciones que el banco todavía no encuentra y no
 * expira un pago que ya tiene una declaración pendiente (el paciente pagó; falta confirmarlo).
 */
class PagosConciliarPagoMovilTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN_URL = 'https://banco.prueba/oauth2/token';

    private const CONSULTA_URL = 'https://banco.prueba/queryPaymentB2P';

    private string $reg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reg = 'conc-' . substr(uniqid(), -6);

        config([
            'pagos.pago_movil.bancaribe.token_url' => self::TOKEN_URL,
            'pagos.pago_movil.bancaribe.consulta_url' => self::CONSULTA_URL,
            'pagos.pago_movil.bancaribe.verificar_certificado' => false,
        ]);

        Cache::flush();
    }

    private function pagoConCuenta(string $conciliacion = CuentaBancaria::CONCILIACION_AUTOMATICA): array
    {
        $user = User::create([
            'name' => 'Dr. Conciliación', 'email' => "{$this->reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr.', 'lastname' => 'Conciliación',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $this->reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $this->reg]);

        $cuenta = CuentaBancaria::create([
            'propietario' => CuentaBancaria::MEDICO,
            'reg_medico' => $this->reg,
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::PAGO_MOVIL,
            'banco_codigo' => '0114',
            'telefono' => '04141234567',
            'titular' => 'Consultorio',
            'documento_tipo' => 'J',
            'documento_numero' => '302597005',
            'conciliacion' => $conciliacion,
            'credenciales' => $conciliacion === CuentaBancaria::CONCILIACION_AUTOMATICA
                ? ['consumer_key' => 'clave', 'consumer_secret' => 'secreto']
                : null,
            'activa' => true,
        ]);

        $metodo = MetodoPago::create([
            'propietario' => MetodoPago::MEDICO,
            'reg_medico' => $this->reg,
            'pasarela' => 'pago_movil',
            'variante' => 'pago_movil',
            'nombre' => 'Pago móvil',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::COBROS,
        ]);

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

        $pago = Pago::create([
            'reg_medico' => $this->reg,
            'beneficiario_tipo' => Pago::BENEFICIARIO_MEDICO,
            'beneficiario_reg_medico' => $this->reg,
            'recaudador' => Pago::RECAUDADOR_MEDICO,
            'canal' => Pago::CANAL_LINK,
            'pagable_tipo' => Pago::PAGABLE_CITA,
            'pagable_id' => $cita->id,
            'concepto' => 'Cita de prueba',
            'metodo_pago_id' => $metodo->id,
            'cuenta_bancaria_id' => $cuenta->id,
            'moneda' => 'VES',
            'monto_e2' => 182500,
            'monto_usd_e2' => 5000,
            'estado' => Pago::PENDIENTE,
            'expira_el' => now()->subHour(),
            'datos_pagador' => [
                'nombre' => 'Paciente', 'documento_tipo' => 'V', 'documento_numero' => '12345678',
                'telefono' => '04141234114',
            ],
        ]);

        return [$pago, $cuenta];
    }

    private function transaccion(Pago $pago, CuentaBancaria $cuenta, array $cambios = []): PagoTransaccion
    {
        return $pago->transacciones()->create(array_merge([
            'cuenta_bancaria_id' => $cuenta->id,
            'referencia' => '000945672279',
            'tipo' => PagoTransaccion::TIPO_PAGO_MOVIL,
            'monto_e2' => 182500,
            'moneda' => 'VES',
            'estado' => PagoTransaccion::PENDIENTE,
            'origen' => PagoTransaccion::ORIGEN_PACIENTE,
        ], $cambios));
    }

    public function test_reintenta_una_declaracion_y_aprueba_si_el_banco_la_encuentra(): void
    {
        [$pago, $cuenta] = $this->pagoConCuenta();
        $transaccion = $this->transaccion($pago, $cuenta);

        // La declaración tiene unos minutos: es la ventana que espera el comando antes de reintentar.
        $transaccion->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::CONSULTA_URL => Http::response([
                'estadoTransaccion' => 'EJ',
                'descripcion' => 'EJECUTADO CORRECTAMENTE',
                'monto' => '1825.00',
            ]),
        ]);

        $this->artisan('pagos:conciliar')->assertSuccessful();

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
    }

    public function test_un_pago_con_declaracion_pendiente_no_se_expira(): void
    {
        [$pago, $cuenta] = $this->pagoConCuenta(CuentaBancaria::CONCILIACION_MANUAL);
        $this->transaccion($pago, $cuenta);

        Http::fake();

        $this->artisan('pagos:conciliar')->assertSuccessful();

        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado);
    }

    public function test_un_pago_sin_declaracion_si_se_expira(): void
    {
        [$pago] = $this->pagoConCuenta(CuentaBancaria::CONCILIACION_MANUAL);

        Http::fake();

        $this->artisan('pagos:conciliar')->assertSuccessful();

        $this->assertSame(Pago::EXPIRADO, $pago->fresh()->estado);
    }

    public function test_no_reintenta_una_declaracion_de_hace_mas_de_dos_dias(): void
    {
        [$pago, $cuenta] = $this->pagoConCuenta();
        $transaccion = $this->transaccion($pago, $cuenta);

        $transaccion->forceFill([
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->save();

        Http::fake();

        $this->artisan('pagos:conciliar')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado);
    }
}
