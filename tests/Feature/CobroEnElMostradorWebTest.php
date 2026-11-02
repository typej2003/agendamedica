<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Specialty;
use App\Models\TasaDeCambio;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **Cobrar una cita en el mostrador** (O-53): la pantalla con los dos caminos —pasarela con OTP y registro
 * manual— y la regla de no cobrar dos veces la misma cita.
 */
class CobroEnElMostradorWebTest extends TestCase
{
    use DatabaseTransactions;

    private Medico $medico;

    private Specialty $gineco;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EspecialidadesYModulosSeeder::class);
        TasaDeCambio::fijar('36,5');

        $reg = 'cobro-mostrador-' . substr(uniqid(), -6);

        $this->user = User::create([
            'name' => 'Doctora del mostrador', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $this->user->assignRole('Medico');

        $this->medico = Medico::create([
            'user_id' => $this->user->id, 'name' => 'Doctora', 'lastname' => 'Del Mostrador',
            'email' => "{$reg}@example.com", 'password' => $this->user->password, 'reg_medico' => $reg,
        ]);

        $this->gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $this->medico->specialties()->attach($this->gineco->id);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $reg]);
    }

    private function conContexto(): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $this->medico->reg_medico,
            'specialty_id' => $this->gineco->id,
            'office_id' => null,
        ]]);
    }

    private function cita(array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => now()->toDateString(),
            'hora_ini' => '09:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'monto' => 50,
        ], $extra));
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

    private function falsificarSyPago(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, 'request/otp')) {
                return Http::response(['transaction_id' => 'tx-mostrador'], 200);
            }

            return Http::response([
                'status' => 'ACCP',
                'transaction_id' => 'tx-mostrador',
                'ref_ibp' => '000123',
            ], 200);
        });
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

    public function test_la_pantalla_muestra_lo_que_falta_cobrar_y_los_dos_caminos(): void
    {
        $cita = $this->cita();
        $this->metodoDeLaPlataforma();

        $this->conContexto()->actingAs($this->user)
            ->get(route('clinica.agenda.cobro', $cita->id))
            ->assertOk()
            ->assertSee('Con la pasarela')
            ->assertSee('Cobro manual')
            ->assertSee('50,00');
    }

    public function test_cobrar_con_la_pasarela_pide_el_codigo_y_deja_el_pago_aprobado(): void
    {
        $this->metodoDeLaPlataforma();
        $this->falsificarSyPago();

        $cita = $this->cita();

        $this->conContexto()->actingAs($this->user)
            ->post(route('clinica.agenda.cobro.pasarela', $cita->id), $this->pagador())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pago = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->firstOrFail();

        $this->assertSame(Pago::CANAL_MOSTRADOR, $pago->canal);
        $this->assertSame(Pago::EN_PROCESO, $pago->estado);
        $this->assertSame(5000, (int) $pago->monto_usd_e2);

        $this->conContexto()->actingAs($this->user)
            ->post(route('clinica.agenda.cobro.confirmar', [$cita->id, $pago->id]), ['otp' => '123456'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(Pago::APROBADO, $pago->fresh()->estado);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->fresh()->estadoPago());
    }

    public function test_no_se_cobra_dos_veces_la_misma_cita(): void
    {
        $cita = $this->cita(['monto_pagado' => 50]);

        $this->conContexto()->actingAs($this->user)
            ->post(route('clinica.agenda.cobro.manual', $cita->id), [
                'recibido' => '50',
                'medio' => 'efectivo',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('recibido');

        $this->assertSame(0, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->count());

        $this->conContexto()->actingAs($this->user)
            ->get(route('clinica.agenda.cobro', $cita->id))
            ->assertOk()
            ->assertSee('ya está cobrada');
    }

    public function test_el_registro_manual_deja_el_pago_aprobado_con_su_medio(): void
    {
        $cita = $this->cita();

        $this->conContexto()->actingAs($this->user)
            ->post(route('clinica.agenda.cobro.manual', $cita->id), [
                'recibido' => '50',
                'medio' => 'punto_venta',
                'nota' => 'Pagó con el punto',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('estado', 'Cobro registrado.');

        $pago = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->firstOrFail();

        $this->assertSame(Pago::APROBADO, $pago->estado);
        $this->assertSame(Pago::CANAL_MOSTRADOR, $pago->canal);
        $this->assertSame('punto_venta', $pago->medio_manual);
        $this->assertSame(5000, (int) $pago->monto_usd_e2);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->fresh()->estadoPago());
        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
    }

    public function test_la_cita_de_otro_medico_no_se_cobra(): void
    {
        $ajena = Cola::create([
            'reg_medico' => 'otro-consultorio',
            'fecha' => now()->toDateString(),
            'hora_ini' => '09:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'monto' => 50,
        ]);

        $this->conContexto()->actingAs($this->user)
            ->get(route('clinica.agenda.cobro', $ajena->id))
            ->assertNotFound();
    }
}
