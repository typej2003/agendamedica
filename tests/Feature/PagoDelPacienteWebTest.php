<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MetodoPago;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Pagar desde «Mis citas»** (punto 4 del cobro de citas): el botón «Pagar» de la cita, la ruta que
 * genera o reusa el link del paciente y los avisos cuando no hay nada que cobrar.
 *
 * Lo que se fija acá:
 *  - el botón aparece sólo en una cita con saldo; ni en la pagada, ni en la que no tiene precio, ni en
 *    una de un día pasado;
 *  - el paciente paga **su** cita: la ruta crea el pago por el saldo y redirige a `/pagar/{token}`;
 *  - abrirla dos veces **no** crea dos pagos: reusa el pendiente;
 *  - la cita de otro paciente responde **404** (no 403);
 *  - una cita ya pagada no crea nada y vuelve con el aviso;
 *  - una cita sin precio o sin método configurado vuelve con el aviso y sin pago;
 *  - sin sesión, la ruta manda al login.
 *
 * `DatabaseTransactions` como el resto de la web clínica (ver MedicAPI/AGENTS.md).
 */
class PagoDelPacienteWebTest extends TestCase
{
    use DatabaseTransactions;

    private User $usuario;

    private Paciente $paciente;

    private Medico $medico;

    private MedicalCenter $centro;

    private Office $office;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Lunes 19 de octubre de 2026: la cita del caso cae el jueves 22 (futura, dentro de la franja).
        Carbon::setTestNow(Carbon::create(2026, 10, 19, 7, 0, 0));

        TasaDeCambio::fijar('36,5');

        $this->usuario = User::create([
            'name' => 'Ana Prueba',
            'email' => 'pago-paciente-' . uniqid() . '@example.com',
            'password' => Hash::make('Secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->usuario->assignRole('Paciente');

        $this->paciente = Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'cedula' => '9' . random_int(1000000, 9999999),
            'telefono' => '0414-5556677',
            'email' => $this->usuario->email,
            'user_id' => $this->usuario->id,
        ]);

        $this->centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede del pago del paciente',
            'address' => 'Calle de prueba',
        ]);

        $this->medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'PagoPaciente',
            'email' => 'medico-pago-paciente-' . uniqid() . '@example.com',
            'reg_medico' => 'test-pago-pac-' . substr(uniqid(), -5),
        ]);

        $this->office = Office::create([
            'medical_center_id' => $this->centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        foreach ([1, 2, 3, 4, 5, 6] as $dia) {
            OfficeSchedule::create([
                'office_id' => $this->office->id,
                'reg_medico' => $this->medico->reg_medico,
                'dia_semana' => $dia,
                'hora_inicio' => '08:00',
                'hora_fin' => '18:00',
                'cupo' => 50,
            ]);
        }

        MedicoPaciente::create([
            'medico_id' => $this->medico->id,
            'paciente_id' => $this->paciente->id,
            'numhistoria' => '1001',
            'reg_medico' => $this->medico->reg_medico,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function cita(array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => '2026-10-22',
            'hora_ini' => '09:00',
            'numhistoria' => '1001',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'medical_center_id' => $this->centro->id,
            'monto' => 50,
            'monto_pagado' => null,
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
            'disponible_para' => MetodoPago::COBROS,
            'orden' => 1,
        ]);
    }

    private function pagosDe(Cola $cita)
    {
        return Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id);
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_el_boton_pagar_aparece_solo_en_la_cita_con_saldo(): void
    {
        // Abonó 20 de 50: debe 30 y es la única que ofrece «Pagar».
        $conSaldo = $this->cita(['monto_pagado' => 20]);
        $pagada = $this->cita(['fecha' => '2026-10-23', 'monto' => 50, 'monto_pagado' => 50]);
        $sinPrecio = $this->cita(['fecha' => '2026-10-24', 'monto' => null]);
        $deUnDiaPasado = $this->cita(['fecha' => '2026-10-10', 'monto' => 50]);

        $respuesta = $this->actingAs($this->usuario)->get(route('paciente.citas'));

        $respuesta->assertOk()
            ->assertSee('Pagar')
            ->assertSee(route('paciente.citas.pagar', $conSaldo->id), false)
            ->assertDontSee(route('paciente.citas.pagar', $pagada->id), false)
            ->assertDontSee(route('paciente.citas.pagar', $sinPrecio->id), false)
            ->assertDontSee(route('paciente.citas.pagar', $deUnDiaPasado->id), false);
    }

    public function test_la_pantalla_del_link_no_muestra_el_panel_del_consultorio(): void
    {
        Http::fake();
        $this->metodoDeLaPlataforma();

        $cita = $this->cita();
        $this->actingAs($this->usuario)->get(route('paciente.citas.pagar', $cita));

        $pago = $this->pagosDe($cita)->sole();

        // El paciente llega con su sesión abierta, pero el link es una página pública: sin la barra
        // lateral del panel del consultorio.
        $this->actingAs($this->usuario)
            ->get(route('pagar.mostrar', $pago->token_publico))
            ->assertOk()
            ->assertSee('Paga tu cita')
            // El `id` sólo lo escribe el armazón del panel; el script del final lo nombra igual, así que
            // la comprobación va sobre el atributo.
            ->assertDontSee('id="panel-sidebar-fondo"', false)
            ->assertDontSee('class="app-sidebar-fondo"', false);
    }

    public function test_el_paciente_paga_su_cita_y_la_ruta_redirige_a_la_pantalla_del_token(): void
    {
        Http::fake();
        $this->metodoDeLaPlataforma();

        // Precio 50, ya abonó 20: la ruta cobra el saldo de 30.
        $cita = $this->cita(['monto_pagado' => 20]);

        $respuesta = $this->actingAs($this->usuario)
            ->get(route('paciente.citas.pagar', $cita));

        $pago = $this->pagosDe($cita)->sole();

        $respuesta->assertRedirect(route('pagar.mostrar', $pago->token_publico));

        $this->assertSame(Pago::PENDIENTE, $pago->estado);
        $this->assertSame(Pago::CANAL_LINK, $pago->canal);
        $this->assertSame(Pago::PAGABLE_CITA, $pago->pagable_tipo);
        $this->assertSame(3000, (int) $pago->monto_usd_e2);
        $this->assertSame($this->usuario->id, (int) $pago->creado_por);

        // La pantalla pública es la misma de siempre: no se duplica nada.
        $this->get(route('pagar.mostrar', $pago->token_publico))
            ->assertOk()
            ->assertSee('Paga tu cita')
            ->assertSee('USD 30,00');
    }

    public function test_abrir_el_pago_dos_veces_no_crea_dos_pagos(): void
    {
        Http::fake();
        $this->metodoDeLaPlataforma();

        $cita = $this->cita();

        $primera = $this->actingAs($this->usuario)->get(route('paciente.citas.pagar', $cita));
        $primero = $this->pagosDe($cita)->sole();

        $segunda = $this->actingAs($this->usuario)->get(route('paciente.citas.pagar', $cita));

        $this->assertSame(1, $this->pagosDe($cita)->count());

        $segundo = $this->pagosDe($cita)->sole();

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame($primero->token_publico, $segundo->token_publico);
        $primera->assertRedirect(route('pagar.mostrar', $primero->token_publico));
        $segunda->assertRedirect(route('pagar.mostrar', $primero->token_publico));
    }

    public function test_la_cita_de_otro_paciente_da_404(): void
    {
        $this->metodoDeLaPlataforma();

        $otro = Paciente::create([
            'nombres' => 'Otra',
            'apellidos' => 'Persona',
            'cedula' => '8' . random_int(1000000, 9999999),
        ]);

        $ajena = Cola::create([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => '2026-10-23',
            'hora_ini' => '09:00',
            'paciente_sinhistoria_id' => $otro->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'medical_center_id' => $this->centro->id,
            'monto' => 50,
        ]);

        $this->actingAs($this->usuario)
            ->get(route('paciente.citas.pagar', $ajena))
            ->assertNotFound();

        $this->assertSame(0, $this->pagosDe($ajena)->count());
    }

    public function test_una_cita_ya_pagada_no_crea_nada_y_vuelve_con_el_aviso(): void
    {
        $this->metodoDeLaPlataforma();

        $cita = $this->cita(['monto' => 50, 'monto_pagado' => 50]);

        $this->actingAs($this->usuario)
            ->get(route('paciente.citas.pagar', $cita))
            ->assertRedirect(route('paciente.citas'))
            ->assertSessionHas('error', 'Esta cita ya está pagada: no hay nada que cobrar.');

        $this->assertSame(0, $this->pagosDe($cita)->count());
    }

    public function test_una_cita_sin_precio_o_sin_metodo_vuelve_con_el_aviso_y_sin_pago(): void
    {
        $sinPrecio = $this->cita(['fecha' => '2026-10-23', 'monto' => null]);

        $this->actingAs($this->usuario)
            ->get(route('paciente.citas.pagar', $sinPrecio))
            ->assertRedirect(route('paciente.citas'))
            ->assertSessionHas('error', 'Esta cita no tiene precio. Ponlo en la cita antes de generar el link.');

        $this->assertSame(0, $this->pagosDe($sinPrecio)->count());

        // Ningún seeder crea métodos de pago: sin uno configurado no hay con qué cobrar.
        $sinMetodo = $this->cita(['fecha' => '2026-10-24']);

        $this->actingAs($this->usuario)
            ->get(route('paciente.citas.pagar', $sinMetodo))
            ->assertRedirect(route('paciente.citas'))
            ->assertSessionHas('error', 'No hay un método de pago configurado para cobrar citas. Pídele al administrador que configure SyPago.');

        $this->assertSame(0, $this->pagosDe($sinMetodo)->count());
    }

    public function test_el_paciente_no_puede_entrar_a_la_ruta_sin_sesion(): void
    {
        $cita = $this->cita();

        $this->get(route('paciente.citas.pagar', $cita))->assertRedirect(route('login'));
    }
}
