<?php

namespace Tests\Feature;

use App\Mail\PagoDeCitaAlMedico;
use App\Mail\PagoDeCitaAlPaciente;
use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MetodoPago;
use App\Models\Paciente;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\Specialty;
use App\Models\TasaDeCambio;
use App\Pagos\Cobros;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * **Los dos correos de una cita pagada** (al médico y al paciente).
 *
 * Lo que se fija acá:
 *  - al aprobarse un pago de cita salen los dos, a los destinatarios correctos;
 *  - el del paciente lleva sede, dirección, día, hora y referencia, y no lleva el motivo ni el token del
 *    link de pago;
 *  - si falta un correo, se manda el otro y queda el omitido en la bitácora;
 *  - un SMTP caído no revierte la aprobación ni deja la cita sin pagar;
 *  - los dos HTML se renderizan sin variables de Blade ni enlaces al pago.
 *
 * `Http::fake` reemplaza a SyPago y `Mail::fake` a SMTP: la prueba no sale a la red. `DatabaseTransactions`
 * es la regla del repo para la web clínica.
 */
class CorreosDePagoDeCitaWebTest extends TestCase
{
    use DatabaseTransactions;

    private Medico $medico;

    private Paciente $paciente;

    private MedicalCenter $centro;

    protected function setUp(): void
    {
        parent::setUp();

        TasaDeCambio::fijar('36,5');

        $this->medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'Correos',
            'prefix' => 'Dr.',
            'email' => 'medico-correos-' . uniqid() . '@example.com',
            'reg_medico' => 'test-correos-' . substr(uniqid(), -5),
        ]);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $this->medico->specialties()->attach($gineco->id);

        $this->paciente = Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Paciente',
            'cedula' => '9' . random_int(1000000, 9999999),
            'telefono' => '0414-5556677',
            'email' => 'paciente-correos-' . uniqid() . '@example.com',
            'reg_medico' => $this->medico->reg_medico,
        ]);

        MedicoPaciente::create([
            'medico_id' => $this->medico->id,
            'paciente_id' => $this->paciente->id,
            'numhistoria' => '1001',
            'reg_medico' => $this->medico->reg_medico,
        ]);

        $this->centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede de los correos',
            'address' => 'Av. Principal, edificio Doctorísimo, piso 2',
        ]);

        // La cita no guarda la sede: `ArmadorDeAgenda` la resuelve desde la historia, como en el legado.
        Historia::create([
            'numhistoria' => '1001',
            'reg_medico' => $this->medico->reg_medico,
            'paciente_id' => $this->paciente->id,
            'medico_id' => $this->medico->id,
            'medical_center_id' => $this->centro->id,
        ]);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, 'request/otp')) {
                return Http::response(['transaction_id' => 'tx-cita'], 200);
            }

            return Http::response([
                'status' => 'ACCP',
                'transaction_id' => 'tx-cita',
                'ref_ibp' => '000123',
            ], 200);
        });
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function cita(array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => '2026-10-22',
            'hora_ini' => '09:00:00',
            'numhistoria' => '1001',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'monto' => 50,
            'monto_pagado' => null,
            // No se lee en el correo, pero tiene que quedar fuera: es un dato de la consulta.
            'motivo' => 'DOLOR PELVICO',
            'tipo' => 'CONSULTA',
        ], $extra));
    }

    private function cuentaDeLaPlataforma(): CuentaBancaria
    {
        return CuentaBancaria::create([
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
    }

    private function metodoDeLaPlataforma(): MetodoPago
    {
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
            'cuenta_bancaria_id' => $this->cuentaDeLaPlataforma()->id,
            'disponible_para' => MetodoPago::AMBOS,
            'orden' => 1,
        ]);
    }

    private function pagador(): array
    {
        return [
            'documento_tipo' => 'V',
            'documento_numero' => '12345678',
            'nombre' => 'Ana Paciente',
            'banco_codigo' => '0134',
            'cuenta' => '01340123456789012345',
        ];
    }

    private function aprobar(Cola $cita, string $canal = Pago::CANAL_LINK): Pago
    {
        $pago = app(Cobros::class)->crearParaCita($cita, $this->metodoDeLaPlataforma(), 5000, $canal);

        $this->assertTrue(app(Cobros::class)->iniciar($pago, $this->pagador())->ok);
        $this->assertTrue(app(Cobros::class)->confirmar($pago->fresh(), '123456')->ok);

        return $pago->fresh();
    }

    private function eventos(Pago $pago, string $evento): array
    {
        return PagoEvento::where('pago_id', $pago->id)
            ->where('evento', $evento)
            ->get()
            ->map(fn (PagoEvento $fila) => (array) $fila->payload)
            ->all();
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_al_aprobarse_el_pago_salen_los_dos_correos_a_los_destinatarios_correctos(): void
    {
        Mail::fake();

        $cita = $this->cita();
        $pago = $this->aprobar($cita);

        $this->assertSame(Pago::APROBADO, $pago->estado);

        Mail::assertSent(PagoDeCitaAlMedico::class, fn (PagoDeCitaAlMedico $mail) => $mail->hasTo($this->medico->email));
        Mail::assertSent(PagoDeCitaAlPaciente::class, fn (PagoDeCitaAlPaciente $mail) => $mail->hasTo($this->paciente->email));

        $this->assertSame([], $this->eventos($pago, 'correo_error'));
        $this->assertSame([], $this->eventos($pago, 'correo_omitido'));
    }

    public function test_el_correo_al_paciente_lleva_lo_necesario_para_llegar_y_la_referencia(): void
    {
        Mail::fake();

        $cita = $this->cita();
        $pago = $this->aprobar($cita);

        Mail::assertSent(PagoDeCitaAlPaciente::class, function (PagoDeCitaAlPaciente $mail) use ($pago) {
            $html = $mail->render();

            $this->assertSame('Pago recibido: cita del 22/10 a las 9:00 AM', $mail->subject);
            $this->assertStringContainsString($this->medico->nombre_mostrar, $html);
            $this->assertStringContainsString('Ginecología y Obstetricia', $html);
            $this->assertStringContainsString('Jueves 22 de octubre de 2026', $html);
            $this->assertStringContainsString('9:00 AM', $html);
            $this->assertStringContainsString($this->centro->name, $html);
            $this->assertStringContainsString($this->centro->address, $html);
            $this->assertStringContainsString('tx-cita', $html);
            $this->assertStringContainsString((string) $pago->referencia, $html);
            $this->assertStringContainsString('VES 1.825,00', $html);

            // Privacidad: ni el motivo de la consulta ni el token del link de pago.
            $this->assertStringNotContainsString('DOLOR PELVICO', $html);
            $this->assertStringNotContainsString((string) $pago->token_publico, $html);
            $this->assertStringNotContainsString('/pagar/', $html);

            return $mail->hasTo($this->paciente->email);
        });
    }

    public function test_el_correo_al_medico_lleva_paciente_monto_fecha_hora_y_canal(): void
    {
        Mail::fake();

        $cita = $this->cita();
        $this->aprobar($cita, Pago::CANAL_RESERVA_ONLINE);

        Mail::assertSent(PagoDeCitaAlMedico::class, function (PagoDeCitaAlMedico $mail) {
            $html = $mail->render();

            $this->assertSame('Te pagaron la cita del 22/10', $mail->subject);
            $this->assertStringContainsString('Ana Paciente', $html);
            $this->assertStringContainsString('Jueves 22 de octubre de 2026', $html);
            $this->assertStringContainsString('9:00 AM', $html);
            $this->assertStringContainsString('VES 1.825,00', $html);
            $this->assertStringContainsString('reserva online', $html);

            return $mail->hasTo($this->medico->email);
        });
    }

    public function test_sin_correo_del_paciente_se_manda_solo_el_del_medico_y_queda_el_omitido(): void
    {
        Mail::fake();
        $this->paciente->update(['email' => null]);

        $cita = $this->cita();
        $pago = $this->aprobar($cita);

        Mail::assertSent(PagoDeCitaAlMedico::class);
        Mail::assertNotSent(PagoDeCitaAlPaciente::class);

        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertContains(
            ['destinatario' => 'paciente', 'motivo' => 'sin_correo'],
            $this->eventos($pago, 'correo_omitido'),
        );
    }

    public function test_sin_correo_del_medico_se_manda_solo_el_del_paciente_y_queda_el_omitido(): void
    {
        Mail::fake();
        $this->medico->update(['email' => null]);

        $cita = $this->cita();
        $pago = $this->aprobar($cita);

        Mail::assertNotSent(PagoDeCitaAlMedico::class);
        Mail::assertSent(PagoDeCitaAlPaciente::class, fn (PagoDeCitaAlPaciente $mail) => $mail->hasTo($this->paciente->email));

        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertContains(
            ['destinatario' => 'medico', 'motivo' => 'sin_correo'],
            $this->eventos($pago, 'correo_omitido'),
        );
    }

    public function test_un_fallo_del_correo_no_revierte_la_aprobacion_ni_deja_la_cita_sin_pagar(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

        $cita = $this->cita();
        $pago = $this->aprobar($cita);

        $this->assertSame(Pago::APROBADO, $pago->estado);

        $cita->refresh();
        $this->assertSame(50.0, (float) $cita->monto_pagado);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->estadoPago());

        $destinatarios = collect($this->eventos($pago, 'correo_error'))->pluck('destinatario')->all();
        $this->assertContains('medico', $destinatarios);
        $this->assertContains('paciente', $destinatarios);
    }

    public function test_los_dos_correos_renderizan_sin_variables_de_blade_ni_enlaces_al_pago(): void
    {
        Mail::fake();

        $this->aprobar($this->cita());

        $medico = null;
        $paciente = null;

        Mail::assertSent(PagoDeCitaAlMedico::class, function (PagoDeCitaAlMedico $mail) use (&$medico) {
            $medico = $mail;

            return true;
        });
        Mail::assertSent(PagoDeCitaAlPaciente::class, function (PagoDeCitaAlPaciente $mail) use (&$paciente) {
            $paciente = $mail;

            return true;
        });

        $htmlDelMedico = (new PagoDeCitaAlMedico($medico->pago, $medico->datos))->render();
        $htmlDelPaciente = (new PagoDeCitaAlPaciente($paciente->pago, $paciente->datos))->render();

        foreach ([$htmlDelMedico, $htmlDelPaciente] as $html) {
            $this->assertStringNotContainsString('{{', $html);
            $this->assertStringNotContainsString('}}', $html);
            $this->assertStringNotContainsString('@if', $html);
            $this->assertStringNotContainsString('@endif', $html);
            $this->assertStringNotContainsString('/pagar/', $html);
            $this->assertStringNotContainsString('http', $html);
        }
    }
}
