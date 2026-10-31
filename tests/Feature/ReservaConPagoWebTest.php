<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\ConfiguracionPago;
use App\Models\CuentaBancaria;
use App\Models\Evolucion;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\MotivoCita;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use App\Pagos\Cobros;
use App\Reservas\DisponibilidadPublica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **Cobrar al reservar** (2026-10-30): la reserva del portal crea la cita y, si el médico cobra un
 * porcentaje de «Cita Online», el pago con **30 minutos** para pagar; si no, la reserva sigue confirmada.
 *
 * También fija la otra mitad de la regla: cuando el pago de la reserva vence, `pagos:conciliar` lo expira
 * y **libera el cupo** (borra la cita) con las guardas de `EliminarCita`. Los pagos de la agenda (canal
 * `link`) conservan su vencimiento de siempre: el final del día de la cita.
 */
class ReservaConPagoWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $fecha;

    private Medico $medico;

    private Office $sede;

    protected function setUp(): void
    {
        parent::setUp();

        // Domingo 18 de octubre de 2026, 07:00 UTC: el bloque del lunes 19 a las 08:00 queda a más de
        // 24 h, dentro de la anticipación del portal.
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 7, 0, 0));

        ConfiguracionPago::actual()->update(['aplica_comision_metodo_en_citas_online' => false]);
        TasaDeCambio::fijar('36,5');

        $this->fecha = Carbon::createFromFormat('!Y-m-d', '2026-10-19');

        $reg = 'test-reserva-' . substr(uniqid(), -6);

        $this->medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'Reserva con pago',
            'email' => "{$reg}@example.com",
            'reg_medico' => $reg,
            'is_active' => true,
        ]);

        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $reg]);

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede de reserva con pago',
            'address' => 'Calle de prueba',
        ]);

        $this->sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $reg,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        OfficeSchedule::create([
            'office_id' => $this->sede->id,
            'reg_medico' => $reg,
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------- Ayudas

    /** @param array<string,string> $extra */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'horario' => $this->fecha->toDateString() . '|08:00|' . $this->sede->id,
            'cedula' => (string) random_int(10000000, 30000000),
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'telefono' => '0414-5556677',
            'email' => 'ana.prueba@example.com',
        ], $extra);
    }

    private function motivo(float $precio = 100.0, string $codigo = 'CONSULTA'): MotivoCita
    {
        return MotivoCita::create([
            'reg_medico' => $this->medico->reg_medico,
            'codigo' => $codigo,
            'tipo_atencion' => 'Consulta',
            'precio' => $precio,
        ]);
    }

    private function conPorcentaje(int $bp): void
    {
        Evolucion::updateOrCreate(
            ['reg_medico' => $this->medico->reg_medico],
            ['clave' => 0, 'porcentaje_cita_online_bp' => $bp],
        );
    }

    private function metodoDeLaPlataforma(): MetodoPago
    {
        $cuenta = CuentaBancaria::create([
            'propietario' => CuentaBancaria::PLATAFORMA,
            'reg_medico' => null,
            'moneda' => 'VES',
            'tipo' => CuentaBancaria::CUENTA,
            'banco_codigo' => '0172',
            'numero' => '01340123456789012345',
            'titular' => 'Doctorisimo, C.A.',
            'documento_tipo' => 'J',
            'documento_numero' => 'J-12345678-9',
            'predeterminada' => true,
            'activa' => true,
        ]);

        return MetodoPago::create([
            'propietario' => MetodoPago::PLATAFORMA,
            'reg_medico' => null,
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
            'disponible_para' => MetodoPago::COBROS,
            'orden' => 1,
        ]);
    }

    /** Hace la reserva completa por el portal: código por sesión y confirmación. */
    private function reservar(array $extra = [])
    {
        $this->post(route('reservas.codigo.enviar', $this->medico), $this->datos($extra))
            ->assertRedirect(route('reservas.codigo', $this->medico))
            ->assertSessionHasNoErrors();

        $codigo = (string) session('reservas.pendiente.codigo');

        return $this->post(route('reservas.confirmar', $this->medico), ['codigo' => $codigo]);
    }

    private function cita(): Cola
    {
        return Cola::where('reg_medico', $this->medico->reg_medico)->firstOrFail();
    }

    private function pagoDeLaCita(): Pago
    {
        return Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $this->cita()->id)
            ->firstOrFail();
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

    private function falsificarSyPago(string $transaccion = 'tx-reserva'): void
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

    // ------------------------------------------------------------------------------------- Casos

    public function test_con_100_por_ciento_crea_el_pago_por_el_precio_completo_y_redirige_a_pagar(): void
    {
        $this->metodoDeLaPlataforma();
        $this->motivo(100.0);
        $this->conPorcentaje(10_000);

        $respuesta = $this->reservar(['motivo' => 'CONSULTA']);

        $pago = $this->pagoDeLaCita();

        $respuesta->assertRedirect(route('pagar.mostrar', $pago->token_publico));
        $this->assertSame(Pago::PENDIENTE, $pago->estado);
        $this->assertSame(Pago::CANAL_RESERVA_ONLINE, $pago->canal);
        $this->assertSame(Pago::PAGABLE_CITA, $pago->pagable_tipo);
        $this->assertSame(10_000, (int) $pago->monto_usd_e2);
        $this->assertSame(100.0, (float) $this->cita()->monto);
        $this->assertTrue($pago->expira_el->equalTo(now()->addMinutes(30)), (string) $pago->expira_el);
    }

    public function test_con_30_por_ciento_cobra_el_30_del_precio(): void
    {
        $this->metodoDeLaPlataforma();
        $this->motivo(100.0);
        $this->conPorcentaje(3_000);

        $this->reservar(['motivo' => 'CONSULTA'])->assertRedirect(
            route('pagar.mostrar', $this->pagoDeLaCita()->token_publico),
        );

        $this->assertSame(3_000, (int) $this->pagoDeLaCita()->monto_usd_e2);
    }

    public function test_con_0_por_ciento_no_crea_pago_y_la_reserva_queda_confirmada(): void
    {
        $this->metodoDeLaPlataforma();
        $this->motivo(100.0);
        $this->conPorcentaje(0);

        $this->reservar(['motivo' => 'CONSULTA'])->assertRedirect(route('reservas.confirmada'));

        $this->assertSame(0, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $this->cita()->id)->count());
        $this->assertSame(1, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_sin_metodo_configurado_no_crea_pago_y_la_reserva_queda_confirmada(): void
    {
        // A propósito no se crea el método de la plataforma.
        $this->motivo(100.0);
        $this->conPorcentaje(10_000);

        $this->reservar(['motivo' => 'CONSULTA'])->assertRedirect(route('reservas.confirmada'));

        $this->assertSame(0, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $this->cita()->id)->count());
        $this->assertSame(1, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_una_cita_sin_precio_no_genera_pago(): void
    {
        $this->metodoDeLaPlataforma();
        $this->conPorcentaje(10_000);

        // Sin motivo, la cita nace sin `monto`.
        $this->reservar()->assertRedirect(route('reservas.confirmada'));

        $this->assertNull($this->cita()->monto);
        $this->assertSame(0, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $this->cita()->id)->count());
    }

    public function test_al_aprobarse_el_pago_la_cita_queda_pagada(): void
    {
        $this->metodoDeLaPlataforma();
        $this->motivo(100.0);
        $this->conPorcentaje(10_000);
        $this->falsificarSyPago();

        $this->reservar(['motivo' => 'CONSULTA'])->assertRedirect(
            route('pagar.mostrar', $this->pagoDeLaCita()->token_publico),
        );

        $pago = $this->pagoDeLaCita();
        $cobros = app(Cobros::class);

        $this->assertTrue($cobros->iniciar($pago, $this->pagador())->ok);
        $this->assertTrue($cobros->confirmar($pago->fresh(), '123456')->ok);

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);

        $cita = $this->cita();
        $this->assertSame(100.0, (float) $cita->monto);
        $this->assertSame(100.0, (float) $cita->monto_pagado);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->estadoPago());
    }

    public function test_al_vencer_el_pago_el_comando_lo_expira_y_libera_la_cita(): void
    {
        $this->metodoDeLaPlataforma();
        $this->motivo(100.0);
        $this->conPorcentaje(10_000);

        $this->reservar(['motivo' => 'CONSULTA']);

        $pago = $this->pagoDeLaCita();
        $citaId = $this->cita()->id;

        // Pasados los 30 minutos, la conciliación de cada minuto lo vence y devuelve el turno.
        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('pagos:conciliar')->assertExitCode(0);

        $this->assertSame(Pago::EXPIRADO, $pago->fresh()->estado);
        $this->assertNull(Cola::find($citaId));
        $this->assertNotNull(
            app(DisponibilidadPublica::class)->opcionEn($this->medico, $this->fecha->toDateString(), '08:00', $this->sede->id),
            'El horario tiene que volver a estar disponible.',
        );
    }

    public function test_no_libera_una_cita_atendida(): void
    {
        $this->metodoDeLaPlataforma();
        $this->motivo(100.0);
        $this->conPorcentaje(10_000);

        $this->reservar(['motivo' => 'CONSULTA']);

        $cita = $this->cita();
        $cita->update(['atendido' => 1]);

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('pagos:conciliar')->assertExitCode(0);

        $this->assertSame(Pago::EXPIRADO, $this->pagoDeLaCita()->estado);
        $this->assertNotNull(Cola::find($cita->id));
    }

    public function test_no_libera_una_cita_con_otro_pago_aprobado(): void
    {
        $metodo = $this->metodoDeLaPlataforma();
        $this->motivo(100.0);
        $this->conPorcentaje(10_000);

        $this->reservar(['motivo' => 'CONSULTA']);

        $cita = $this->cita();

        // Otro cobro de la cita, ya aprobado (por ejemplo, el mostrador o un link de la agenda).
        $otro = app(Cobros::class)->crearParaCita($cita, $metodo, 10_000, Pago::CANAL_LINK);
        $otro->update(['estado' => Pago::APROBADO]);

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('pagos:conciliar')->assertExitCode(0);

        $this->assertSame(Pago::EXPIRADO, $this->pagoDeLaCita()->estado);
        $this->assertNotNull(Cola::find($cita->id));
    }

    public function test_el_link_de_la_agenda_sigue_venciendo_al_final_del_dia_de_la_cita(): void
    {
        $metodo = $this->metodoDeLaPlataforma();

        $fecha = now()->addDays(5)->format('Y-m-d');
        $cita = Cola::create([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => $fecha,
            'hora_ini' => '08:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'monto' => 100,
        ]);

        $pago = app(Cobros::class)->crearParaCita($cita, $metodo, 10_000, Pago::CANAL_LINK);

        $expira = $pago->expira_el->timezone(FechaDelConsultorio::zona());

        $this->assertSame(Pago::CANAL_LINK, $pago->canal);
        $this->assertSame($fecha, $expira->format('Y-m-d'));
        $this->assertSame('23:59:59', $expira->format('H:i:s'));
        $this->assertTrue($pago->expira_el->greaterThan(now()->addDays(4)));
    }
}
