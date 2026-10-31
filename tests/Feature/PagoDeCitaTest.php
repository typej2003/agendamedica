<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\ConfiguracionPago;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\ReglaDeComision;
use App\Models\SyncChange;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Pagos\Cobros;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * **El cobro de una cita** (ola 4, paso 1): crear la intención, congelar comisiones y liquidación, y
 * dejar la cita pagada cuando SyPago aprueba.
 *
 * Es la mitad del motor: todavía no hay link público ni pantallas del paciente. Lo que se fija acá es
 * que el pago nace con el médico como beneficiario, que la cascada de comisiones respeta la
 * configuración de las citas online y que la aprobación reusa `CobrarCita` sin inventar un estado.
 */
class PagoDeCitaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Cada prueba arranca con la configuración por defecto: la comisión del método no se suma a
        // la de cita online.
        ConfiguracionPago::actual()->update(['aplica_comision_metodo_en_citas_online' => false]);

        // La tasa que usa el cobro en VES; las pruebas que la necesitan distinta la vuelven a fijar.
        TasaDeCambio::fijar('36,5');
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoDePrueba(): Medico
    {
        $reg = 'cita-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dra. de prueba',
            'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Dra. de prueba',
            'lastname' => 'Cobros',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $reg,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return $medico;
    }

    private function cuentaDe(string $propietario, ?string $regMedico, string $moneda = 'VES'): CuentaBancaria
    {
        return CuentaBancaria::create([
            'propietario' => $propietario,
            'reg_medico' => $regMedico,
            'moneda' => $moneda,
            'tipo' => CuentaBancaria::CUENTA,
            'banco_codigo' => '0172',
            'numero' => '01340123456789012345',
            'titular' => $propietario === CuentaBancaria::PLATAFORMA ? 'Doctorisimo, C.A.' : 'Dra. de prueba',
            'documento_tipo' => 'J',
            'documento_numero' => 'J-12345678-9',
            'predeterminada' => true,
            'activa' => true,
        ]);
    }

    private function metodoDePrueba(string $propietario, ?string $regMedico, CuentaBancaria $cuenta, ?int $comisionBp = null): MetodoPago
    {
        return MetodoPago::create([
            'propietario' => $propietario,
            'reg_medico' => $regMedico,
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
            'comision_bp' => $comisionBp,
            'disponible_para' => MetodoPago::AMBOS,
            'orden' => 1,
        ]);
    }

    private function citaDePrueba(Medico $medico, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'fecha' => '2026-10-05',
            'hora_ini' => '08:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'monto' => null,
            'monto_pagado' => null,
        ], $extra));
    }

    private function reglaGlobal(string $tipo, int $bp): ReglaDeComision
    {
        return ReglaDeComision::create([
            'tipo' => $tipo,
            'ambito' => ReglaDeComision::GLOBAL,
            'porcentaje_bp' => $bp,
            'vigente_desde' => now()->subMinute(),
            'activo' => true,
        ]);
    }

    private function metodoDeLaPlataforma(): MetodoPago
    {
        return $this->metodoDePrueba(
            MetodoPago::PLATAFORMA,
            null,
            $this->cuentaDe(CuentaBancaria::PLATAFORMA, null),
        );
    }

    private function pagador(): array
    {
        return [
            'documento_tipo' => 'V',
            'documento_numero' => '12345678',
            'nombre' => 'Paciente de prueba',
            'banco_codigo' => '0134',
            'cuenta' => '01340123456789012345',
        ];
    }

    private function falsificarSyPago(string $transaccion = 'tx-cita'): void
    {
        Http::fake(function (ClientRequest $request) use ($transaccion) {
            $url = $request->url();

            if (str_contains($url, 'request/otp')) {
                return Http::response(['transaction_id' => $transaccion], 200);
            }
            if (str_contains($url, 'transaction/otp')) {
                return Http::response([
                    'status' => 'ACCP',
                    'transaction_id' => $transaccion,
                    'ref_ibp' => '000123',
                ], 200);
            }
            if (str_contains($url, 'transaction/' . $transaccion)) {
                return Http::response([
                    'status' => 'ACCP',
                    'transaction_id' => $transaccion,
                    'ref_ibp' => '000123',
                ], 200);
            }

            return Http::response([], 404);
        });
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_crea_la_intencion_con_beneficiario_medico_canal_y_montos_congelados(): void
    {
        TasaDeCambio::fijar('36,5');

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 5000);

        $this->assertSame(Pago::PENDIENTE, $pago->estado);
        $this->assertSame(Pago::BENEFICIARIO_MEDICO, $pago->beneficiario_tipo);
        $this->assertSame($medico->reg_medico, $pago->beneficiario_reg_medico);
        $this->assertSame($medico->reg_medico, $pago->reg_medico);
        $this->assertSame(Pago::RECAUDADOR_PLATAFORMA, $pago->recaudador);
        $this->assertSame(Pago::CANAL_LINK, $pago->canal);
        $this->assertSame(Pago::PAGABLE_CITA, $pago->pagable_tipo);
        $this->assertSame($cita->id, $pago->pagable_id);
        $this->assertSame($metodo->id, $pago->metodo_pago_id);

        $this->assertSame('VES', $pago->moneda);
        $this->assertSame(5000, (int) $pago->monto_usd_e2);
        $this->assertSame(182500, (int) $pago->monto_e2); // 50 USD × 36,5 = 1.825,00 VES
        $this->assertSame('36.500000', (string) $pago->tasa);
        $this->assertSame(0, (int) $pago->comision_total_usd_e2);
        $this->assertSame(5000, (int) $pago->neto_usd_e2);
        $this->assertSame(182500, (int) $pago->neto_ves_e2);
        $this->assertSame(Pago::LIQUIDACION_PENDIENTE, $pago->estado_liquidacion);
        $this->assertTrue($pago->expira_el->isFuture());
    }

    public function test_la_cascada_del_metodo_y_la_cita_online_suma_el_bruto(): void
    {
        TasaDeCambio::fijar('36,5');
        ConfiguracionPago::actual()->update(['aplica_comision_metodo_en_citas_online' => true]);

        $this->reglaGlobal(ReglaDeComision::TIPO_METODO, 1_000);       // 10 %
        $this->reglaGlobal(ReglaDeComision::TIPO_CITA_ONLINE, 3_000);  // 30 % sobre el remanente

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        // 100,00 USD: método 10,00 → base 90,00 → cita online 27,00 → neto 63,00.
        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 10_000, Pago::CANAL_RESERVA_ONLINE);

        $this->assertSame(1_000, (int) $pago->comision_metodo_usd_e2);
        $this->assertSame(2_700, (int) $pago->comision_cita_online_usd_e2);
        $this->assertSame(3_700, (int) $pago->comision_total_usd_e2);
        $this->assertSame(6_300, (int) $pago->neto_usd_e2);

        // Las partes suman el bruto, en USD y en VES.
        $this->assertSame((int) $pago->monto_usd_e2, (int) $pago->comision_total_usd_e2 + (int) $pago->neto_usd_e2);
        $this->assertSame((int) $pago->monto_e2, (int) $pago->comision_total_ves_e2 + (int) $pago->neto_ves_e2);
        $this->assertSame(365000, (int) $pago->monto_e2);
        $this->assertSame(135050, (int) $pago->comision_total_ves_e2);
        $this->assertSame(229950, (int) $pago->neto_ves_e2);
    }

    public function test_por_defecto_la_comision_del_metodo_no_se_cobra_en_una_cita_online(): void
    {
        $this->reglaGlobal(ReglaDeComision::TIPO_METODO, 1_000);
        $this->reglaGlobal(ReglaDeComision::TIPO_CITA_ONLINE, 3_000);

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 10_000, Pago::CANAL_RESERVA_ONLINE);

        $this->assertSame(0, (int) $pago->comision_metodo_usd_e2);
        $this->assertSame(3_000, (int) $pago->comision_cita_online_usd_e2);
        $this->assertSame(3_000, (int) $pago->comision_total_usd_e2);
        $this->assertSame(7_000, (int) $pago->neto_usd_e2);
    }

    public function test_el_recaudador_y_la_liquidacion_dependen_del_dueno_del_metodo(): void
    {
        // Hay una regla de comisión del método, pero sólo se cobra cuando el método es de la plataforma.
        $this->reglaGlobal(ReglaDeComision::TIPO_METODO, 1_000);

        $medico = $this->medicoDePrueba();
        $cita = $this->citaDePrueba($medico);

        $metodoDelMedico = $this->metodoDePrueba(
            MetodoPago::MEDICO,
            $medico->reg_medico,
            $this->cuentaDe(CuentaBancaria::MEDICO, $medico->reg_medico),
        );

        $delMedico = app(Cobros::class)->crearParaCita($cita, $metodoDelMedico, 5000, Pago::CANAL_MOSTRADOR);

        $this->assertSame(Pago::RECAUDADOR_MEDICO, $delMedico->recaudador);
        $this->assertSame(Pago::LIQUIDACION_NO_APLICA, $delMedico->estado_liquidacion);
        $this->assertSame(0, (int) $delMedico->comision_metodo_usd_e2);

        $deLaPlataforma = app(Cobros::class)->crearParaCita($cita, $this->metodoDeLaPlataforma(), 5000, Pago::CANAL_MOSTRADOR);

        $this->assertSame(Pago::RECAUDADOR_PLATAFORMA, $deLaPlataforma->recaudador);
        $this->assertSame(Pago::LIQUIDACION_PENDIENTE, $deLaPlataforma->estado_liquidacion);
        $this->assertSame(500, (int) $deLaPlataforma->comision_metodo_usd_e2);
    }

    public function test_el_token_publico_es_unico_y_no_vacio(): void
    {
        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $primero = app(Cobros::class)->crearParaCita($cita, $metodo, 5000);
        $segundo = app(Cobros::class)->crearParaCita($cita, $metodo, 5000);

        $this->assertNotSame('', (string) $primero->token_publico);
        $this->assertNotSame('', (string) $segundo->token_publico);
        $this->assertNotSame($primero->token_publico, $segundo->token_publico);
        $this->assertLessThanOrEqual(64, strlen((string) $primero->token_publico));
    }

    public function test_aprobar_el_pago_deja_la_cita_pagada_con_la_accion_del_mostrador(): void
    {
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 5000);

        $inicio = app(Cobros::class)->iniciar($pago, $this->pagador());
        $this->assertTrue($inicio->ok, $inicio->mensaje);

        $confirmacion = app(Cobros::class)->confirmar($pago->fresh(), '123456');
        $this->assertTrue($confirmacion->ok, $confirmacion->mensaje);

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);

        $cita->refresh();
        $this->assertSame(50.0, (float) $cita->monto);
        $this->assertSame(50.0, (float) $cita->monto_pagado);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->estadoPago());

        // El rastro es el de `CobrarCita`: una fila de `sync_changes` por columna escrita.
        $this->assertTrue(
            SyncChange::where('table_name', 'cola')
                ->where('record_id', $cita->id)
                ->where('column_name', 'monto_pagado')
                ->where('source', 'web')
                ->exists()
        );
    }

    public function test_un_anticipo_sobre_una_cita_con_precio_la_deja_abonada(): void
    {
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        // La cita ya nace con su precio (el del motivo) y el paciente adelanta la mitad.
        $cita = $this->citaDePrueba($medico, ['monto' => 100, 'monto_pagado' => null]);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 5_000);

        $this->assertTrue(app(Cobros::class)->iniciar($pago, $this->pagador())->ok);
        $this->assertTrue(app(Cobros::class)->confirmar($pago->fresh(), '123456')->ok);

        $cita->refresh();
        $this->assertSame(100.0, (float) $cita->monto, 'el precio de la cita no se pisa con el anticipo');
        $this->assertSame(50.0, (float) $cita->monto_pagado);
        $this->assertSame(Cola::PAGO_ABONADA, $cita->estadoPago());
        $this->assertSame(50.0, $cita->montoPendiente());
    }

    public function test_la_aprobacion_registra_el_pago_aunque_la_cita_ya_no_exista(): void
    {
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 5_000);
        $cita->delete();

        $this->assertTrue(app(Cobros::class)->iniciar($pago, $this->pagador())->ok);
        $this->assertTrue(app(Cobros::class)->confirmar($pago->fresh(), '123456')->ok);

        // La plata entró: el pago se aprueba y queda el rastro de que la cita ya no estaba.
        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertTrue(
            PagoEvento::where('pago_id', $pago->id)->where('evento', 'cita_inexistente')->exists()
        );
    }

    public function test_un_segundo_pago_suma_lo_pagado_sin_revertir_la_aprobacion(): void
    {
        TasaDeCambio::fijar('36,5');
        $this->falsificarSyPago();

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico, ['monto' => 50, 'monto_pagado' => 50]);

        // La secretaria ya la había marcado cobrada en el mostrador y el link se cobra igual: el
        // dinero entró dos veces y las dos tienen que quedar registradas, no perderse.
        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 5_000);

        $this->assertTrue(app(Cobros::class)->iniciar($pago, $this->pagador())->ok);
        $this->assertTrue(app(Cobros::class)->confirmar($pago->fresh(), '123456')->ok);

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);

        $cita->refresh();
        $this->assertSame(50.0, (float) $cita->monto);
        $this->assertSame(100.0, (float) $cita->monto_pagado);
        $this->assertSame(0.0, $cita->montoPendiente());
    }

    public function test_el_link_vale_hasta_el_final_del_dia_de_la_cita(): void
    {
        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();

        $fecha = now()->addDays(5)->format('Y-m-d');
        $cita = $this->citaDePrueba($medico, ['fecha' => $fecha]);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 5000);

        // El final del día es el del consultorio, no el de UTC.
        $expira = $pago->expira_el->timezone(FechaDelConsultorio::zona());

        $this->assertSame($fecha, $expira->format('Y-m-d'));
        $this->assertSame('23:59:59', $expira->format('H:i:s'));
        $this->assertTrue($pago->expira_el->isFuture());
        // Ya no son los 15 minutos de la tasa congelada.
        $this->assertTrue($pago->expira_el->greaterThan(now()->addDays(4)));
    }

    public function test_un_link_de_una_cita_que_ya_paso_da_un_dia_para_pagar(): void
    {
        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();

        $cita = $this->citaDePrueba($medico, ['fecha' => now()->subDays(3)->format('Y-m-d')]);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 5000);

        $this->assertTrue($pago->expira_el->isFuture());
        $this->assertTrue($pago->expira_el->greaterThan(now()->addHours(23)));
        $this->assertTrue($pago->expira_el->lessThan(now()->addHours(25)));
    }

    public function test_al_iniciar_el_pago_se_cobra_con_la_tasa_del_dia(): void
    {
        TasaDeCambio::fijar('36,5');
        $enviado = [];

        Http::fake(function (ClientRequest $request) use (&$enviado) {
            if (str_contains($request->url(), 'request/otp')) {
                $enviado = $request->data();

                return Http::response(['transaction_id' => 'tx-tasa'], 200);
            }

            return Http::response(['transaction_id' => 'tx-tasa'], 200);
        });

        $this->reglaGlobal(ReglaDeComision::TIPO_METODO, 1_000); // 10 %

        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 10_000); // 100,00 USD
        $this->assertSame(365000, (int) $pago->monto_e2); // a 36,5

        // La tasa se mueve y el paciente paga recién ahí: se cobra con la del momento.
        TasaDeCambio::fijar('40');

        $inicio = app(Cobros::class)->iniciar($pago, $this->pagador());
        $this->assertTrue($inicio->ok, $inicio->mensaje);

        $pago->refresh();
        $this->assertSame(400000, (int) $pago->monto_e2);
        $this->assertSame('40.000000', (string) $pago->tasa);
        // La comisión en USD no se mueve; su equivalente en VES sí.
        $this->assertSame(1_000, (int) $pago->comision_total_usd_e2);
        $this->assertSame(40000, (int) $pago->comision_total_ves_e2);
        $this->assertSame(360000, (int) $pago->neto_ves_e2);
        // El débito que se pide a SyPago lleva el monto nuevo (4.000,00 VES).
        $this->assertSame(4000.0, (float) $enviado['amount']['amt']);
    }

    public function test_rechaza_un_canal_que_no_es_de_cita(): void
    {
        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canal');

        app(Cobros::class)->crearParaCita($cita, $metodo, 5000, Pago::CANAL_PLAN);
    }

    public function test_rechaza_un_monto_que_no_es_positivo(): void
    {
        $medico = $this->medicoDePrueba();
        $metodo = $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mayor que cero');

        app(Cobros::class)->crearParaCita($cita, $metodo, 0);
    }
}
