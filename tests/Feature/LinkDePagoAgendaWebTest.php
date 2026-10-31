<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\CuentaBancaria;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MetodoPago;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Pago;
use App\Models\Specialty;
use App\Models\TasaDeCambio;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El link de pago desde la agenda** (punto 3 del cobro de citas): la acción de la fila, la pantalla que
 * genera y comparte el link, y las reglas de creación.
 *
 * Lo que se fija acá:
 *  - el link se genera desde la agenda y es el de la ruta pública del token;
 *  - abrir la pantalla dos veces **no** crea dos pagos: reusa el pendiente;
 *  - si el precio cambió, el pendiente viejo queda `cancelado` y nace uno nuevo;
 *  - una cita sin precio no genera nada y manda a ponerlo en la cita;
 *  - sin método de pago configurado no se genera nada;
 *  - una cita ya pagada no genera link;
 *  - la secretaría puede y un usuario sin rol no.
 *
 * `DatabaseTransactions` como el resto de la web clínica (ver MedicAPI/AGENTS.md).
 */
class LinkDePagoAgendaWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        // Un lunes futuro cualquiera: los bloques se siembran con `dia_semana = 1`.
        $this->lunes = Carbon::parse('2026-11-09');

        TasaDeCambio::fijar('36,5');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'link-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'link-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Link',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'link-secre-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function centro(string $nombre): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => $nombre,
            'address'    => 'Dirección de prueba',
        ]);
    }

    private function sede(Medico $medico): Office
    {
        $sede = Office::create([
            'medical_center_id' => $this->centro('Sede del link')->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => 'L1',
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);

        OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $medico->reg_medico,
            'dia_semana'  => 1,
            'hora_inicio' => '08:00',
            'hora_fin'    => '12:00',
        ]);

        return $sede;
    }

    private function citaDePrueba(Medico $medico, Office $sede, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => $this->lunes->toDateString(),
            'hora_ini'          => '08:00:00',
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => 1,
            'atendido'          => 0,
            'estado'            => 0,
            'monto'             => 50,
            'monto_pagado'      => null,
        ], $extra));
    }

    private function cuentaDeLaPlataforma(): CuentaBancaria
    {
        return CuentaBancaria::create([
            'propietario'     => CuentaBancaria::PLATAFORMA,
            'reg_medico'      => null,
            'moneda'          => 'VES',
            'tipo'            => CuentaBancaria::CUENTA,
            'banco_codigo'    => '0172',
            'numero'          => '01340123456789012345',
            'titular'         => 'Doctorisimo, C.A.',
            'documento_tipo'  => 'J',
            'documento_numero' => 'J-12345678-9',
            'predeterminada'  => true,
            'activa'          => true,
        ]);
    }

    private function metodoDeLaPlataforma(): MetodoPago
    {
        return MetodoPago::create([
            'propietario'      => MetodoPago::PLATAFORMA,
            'reg_medico'       => null,
            'pasarela'         => 'sypago',
            'variante'         => 'debito_inmediato',
            'nombre'           => 'SyPago · Débito inmediato',
            'activo'           => true,
            'entorno'          => MetodoPago::PRODUCCION,
            'credenciales'     => [
                'access_token' => 'token-de-prueba',
                'host'         => 'https://app.sypago.net:8086/api/v1/',
            ],
            'cuenta_bancaria_id' => $this->cuentaDeLaPlataforma()->id,
            'disponible_para'  => MetodoPago::COBROS,
            'orden'            => 1,
        ]);
    }

    private function urlDelLink(Cola $cita): string
    {
        return route('clinica.agenda.link-de-pago', $cita->id);
    }

    private function agendaDelDia(Cola $cita): string
    {
        return '/clinica/agenda?vista=dia&fecha=' . $cita->fecha->format('Y-m-d') . '&sede=todas';
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_la_agenda_ofrece_el_link_y_la_pantalla_lo_genera(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico, $sede);

        $agenda = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->agendaDelDia($cita));

        $agenda->assertOk();
        $agenda->assertSee('Link de pago');
        $agenda->assertSee($this->urlDelLink($cita), false);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita));

        $respuesta->assertOk();

        $pago = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->sole();

        $this->assertSame(Pago::PENDIENTE, $pago->estado);
        $this->assertSame(Pago::CANAL_LINK, $pago->canal);
        $this->assertSame(5000, (int) $pago->monto_usd_e2);
        $this->assertSame(Pago::PAGABLE_CITA, $pago->pagable_tipo);
        $this->assertSame($user->id, (int) $pago->creado_por);
        $this->assertNotEmpty($pago->token_publico);

        $respuesta->assertSee(route('pagar.mostrar', $pago->token_publico), false);
        $respuesta->assertSee('Copiar');
        $respuesta->assertSee('Enviar por WhatsApp');
        $respuesta->assertSee('https://wa.me/?text=', false);
        $respuesta->assertSee('USD 50,00');
        $respuesta->assertSee('la tasa del día');
    }

    public function test_abrir_la_pantalla_dos_veces_reusa_el_pendiente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico, $sede);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita))
            ->assertOk();

        $primero = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->sole();

        $segunda = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita));

        $segunda->assertOk();

        $this->assertSame(1, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->count());

        $segundo = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->sole();

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame($primero->token_publico, $segundo->token_publico);
        $segunda->assertSee(route('pagar.mostrar', $primero->token_publico), false);
    }

    public function test_si_el_precio_de_la_cita_cambia_el_pendiente_viejo_queda_cancelado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico, $sede, ['monto' => 50]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita))
            ->assertOk();

        $viejo = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->sole();

        $cita->update(['monto' => 60]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita))
            ->assertOk();

        $viejo->refresh();
        $this->assertSame(Pago::CANCELADO, $viejo->estado);

        $nuevo = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->where('estado', Pago::PENDIENTE)
            ->sole();

        $this->assertNotSame($viejo->id, $nuevo->id);
        $this->assertNotSame($viejo->token_publico, $nuevo->token_publico);
        $this->assertSame(6000, (int) $nuevo->monto_usd_e2);
        $this->assertSame(2, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->count());
    }

    public function test_una_cita_sin_precio_no_genera_link_y_muestra_el_aviso(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico, $sede, ['monto' => null]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita));

        $respuesta->assertOk();
        $respuesta->assertSee('Esta cita no tiene precio', false);
        $respuesta->assertSee(route('clinica.agenda.editar', $cita->id), false);

        $this->assertSame(0, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->count());
    }

    public function test_sin_metodo_de_pago_configurado_no_genera_link(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $cita = $this->citaDePrueba($medico, $sede, ['monto' => 50]);

        // Ningún seeder crea métodos de pago: sin uno configurado no hay con qué cobrar.
        $this->assertSame(0, MetodoPago::deLaPlataforma()->count());

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita));

        $respuesta->assertOk();
        $respuesta->assertSee('No hay un método de pago configurado', false);

        $this->assertSame(0, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->count());
    }

    public function test_una_cita_ya_pagada_no_genera_link(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico, $sede, ['monto' => 50, 'monto_pagado' => 50]);

        // La fila no ofrece la acción cuando la cita ya está pagada...
        $agenda = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->agendaDelDia($cita));

        $agenda->assertOk();
        $agenda->assertDontSee($this->urlDelLink($cita), false);

        // ...y si se entra por URL directa, la pantalla lo avisa y no crea nada.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDelLink($cita));

        $respuesta->assertOk();
        $respuesta->assertSee('ya está pagada', false);

        $this->assertSame(0, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->count());
    }

    public function test_la_secretaria_puede_generar_el_link_y_un_usuario_sin_rol_no(): void
    {
        [$medicoUser, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->metodoDeLaPlataforma();
        $cita = $this->citaDePrueba($medico, $sede);

        $secretaria = $this->secretariaConAcceso($medico);
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlDelLink($cita))
            ->assertOk();

        $this->assertSame(1, Pago::where('pagable_tipo', Pago::PAGABLE_CITA)->where('pagable_id', $cita->id)->count());

        $sinRol = User::create([
            'name'                 => 'Sin rol',
            'email'                => 'link-sinrol-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);

        $this->conContexto($sinRol, $medico, $gineco)->actingAs($sinRol)
            ->get($this->urlDelLink($cita))
            ->assertForbidden();
    }
}
