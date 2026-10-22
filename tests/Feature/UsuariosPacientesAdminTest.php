<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\UsuariosPacientes;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\User;
use App\Paciente\BloqueoDePacientes;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Usuarios › Pacientes** (cuentas de paciente que se crean solas): el listado de lectura y el bloqueo de
 * emergencia, que cierra los tres puntos de entrada que crean citas (reserva pública, chatbot y el portal
 * «Mis citas») con una sola regla (`BloqueoDePacientes`).
 */
class UsuariosPacientesAdminTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $fecha;

    private Medico $medico;

    private Office $sede;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Domingo 18 de octubre de 2026, 03:00 en Caracas: el bloque del lunes 19 a las 08:00 está dentro de la ventana.
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 7, 0, 0));
        $this->fecha = Carbon::createFromFormat('!Y-m-d', '2026-10-19');

        $this->medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'Bloqueos',
            'email' => 'bloqueos-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'test-bloq' . substr(uniqid(), -6),
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico]);

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede de bloqueos',
            'address' => 'Calle de prueba',
        ]);
        $this->sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);
        OfficeSchedule::create([
            'office_id' => $this->sede->id,
            'reg_medico' => $this->medico->reg_medico,
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

    private function usuario(string $rol, string $nombre = 'Usuario'): User
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'bp-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    /** Una cuenta de paciente con su ficha enlazada (cédula y teléfono propios). */
    private function cuentaDePaciente(string $cedula, string $telefono, string $nombres = 'Ana'): User
    {
        $user = $this->usuario('Paciente', $nombres . ' Cuenta');
        Paciente::create([
            'cedula' => $cedula,
            'nombres' => $nombres,
            'apellidos' => 'Cuenta',
            'telefono' => $telefono,
            'email' => $user->email,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    private function bloquear(User $paciente): void
    {
        app(BloqueoDePacientes::class)->bloquear($paciente, 'reservas falsas', $this->usuario('Root', 'Admin'));
    }

    public function test_el_listado_muestra_las_cuentas_con_sus_citas_y_sin_datos_clinicos(): void
    {
        $cuenta = $this->cuentaDePaciente('12345001', '584141110001', 'Zulima');
        $ficha = $cuenta->paciente;
        Cola::create(['reg_medico' => $this->medico->reg_medico, 'fecha' => '2026-10-19', 'paciente_sinhistoria_id' => $ficha->id, 'hora_ini' => '09:00', 'atendido' => 0, 'estado' => 2]);
        Cola::create(['reg_medico' => $this->medico->reg_medico, 'fecha' => '2026-11-02', 'paciente_sinhistoria_id' => $ficha->id, 'hora_ini' => '09:00', 'atendido' => 0, 'estado' => 2]);

        $this->actingAs($this->usuario('Root'));

        $this->get(route('admin.cuentas.pacientes'))
            ->assertOk()
            ->assertSee('Usuarios · Pacientes');

        Livewire::test(UsuariosPacientes::class)
            ->set('search', '12345001')
            ->assertSee('Zulima Cuenta')
            ->assertSee('12345001')
            ->assertSee('584141110001')
            ->assertSee('Activo')
            ->assertSee('02/11/2026')
            ->assertViewHas('citas', fn ($c) => ($c[$ficha->id]['total'] ?? 0) === 2)
            ->set('search', 'no-existe-nadie')
            ->assertDontSee('Zulima Cuenta');
    }

    public function test_el_buscador_y_el_filtro_de_estado(): void
    {
        $uno = $this->cuentaDePaciente('12345002', '584141110002', 'Ximena');
        $dos = $this->cuentaDePaciente('12345003', '584141110003', 'Yolanda');
        $this->bloquear($dos);

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(UsuariosPacientes::class)
            ->set('estado', 'bloqueados')->assertSee('Yolanda Cuenta')->assertDontSee('Ximena Cuenta')
            ->set('estado', 'activos')->assertSee('Ximena Cuenta')->assertDontSee('Yolanda Cuenta')
            ->set('estado', 'todos')->set('search', $uno->email)->assertSee('Ximena Cuenta')->assertDontSee('Yolanda Cuenta');
    }

    public function test_bloquear_y_desbloquear_desde_el_panel_deja_auditoria(): void
    {
        $admin = $this->usuario('Root');
        $cuenta = $this->cuentaDePaciente('12345004', '584141110004');
        $this->actingAs($admin);

        Livewire::test(UsuariosPacientes::class)
            ->call('abrir', 'bloquear', $cuenta->id)
            ->set('motivo', 'spam')
            ->call('confirmarBloqueo')
            ->assertSee('Bloqueado');

        $cuenta->refresh();
        $this->assertFalse((bool) $cuenta->is_active);
        $this->assertSame('spam', $cuenta->blocked_reason);
        $this->assertNotNull($cuenta->blocked_at);
        $this->assertSame($admin->id, (int) $cuenta->blocked_by);
        $this->assertSame('Ana', $cuenta->paciente->nombres, 'La ficha del paciente no se toca ni se borra.');

        Livewire::test(UsuariosPacientes::class)
            ->call('abrir', 'desbloquear', $cuenta->id)
            ->call('confirmarDesbloqueo');

        $cuenta->refresh();
        $this->assertTrue((bool) $cuenta->is_active);
        $this->assertNull($cuenta->blocked_reason);
        $this->assertNull($cuenta->blocked_at);
        $this->assertNull($cuenta->blocked_by);
    }

    public function test_no_se_bloquea_con_esta_pantalla_a_un_medico_ni_a_un_administrador(): void
    {
        $this->actingAs($this->usuario('Root'));
        $medico = $this->usuario('Medico', 'Doc');
        $medico->assignRole('Paciente');

        Livewire::test(UsuariosPacientes::class)
            ->call('abrir', 'bloquear', $medico->id)
            ->call('confirmarBloqueo')
            ->assertHasErrors('cuenta');

        $this->assertTrue((bool) $medico->fresh()->is_active);
    }

    public function test_quien_no_es_administrador_no_entra_ni_actua(): void
    {
        $cuenta = $this->cuentaDePaciente('12345005', '584141110005');

        $this->actingAs($this->usuario('Medico'));
        $this->get(route('admin.cuentas.pacientes'))->assertForbidden();
        Livewire::test(UsuariosPacientes::class)->assertForbidden();

        $this->actingAs($this->usuario('Paciente'));
        $this->get(route('admin.cuentas.pacientes'))->assertForbidden();

        $this->assertTrue((bool) $cuenta->fresh()->is_active);
    }

    public function test_un_paciente_bloqueado_no_inicia_sesion_ni_entra_a_mis_citas(): void
    {
        $cuenta = $this->cuentaDePaciente('12345006', '584141110006');
        $this->bloquear($cuenta);

        $this->actingAs($cuenta)
            ->get(route('paciente.citas'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_la_reserva_publica_rechaza_a_un_paciente_bloqueado_y_la_restablece_al_desbloquear(): void
    {
        $cuenta = $this->cuentaDePaciente('12345007', '0414-1110007');
        $this->bloquear($cuenta);

        $datos = [
            'horario' => $this->fecha->toDateString() . '|08:00|' . $this->sede->id,
            'cedula' => '12345007',
            'nombres' => 'Ana',
            'apellidos' => 'Cuenta',
            'telefono' => '0414-1110007',
            'email' => 'otro-correo@example.com',
        ];

        $this->post(route('reservas.codigo.enviar', $this->medico), $datos)
            ->assertSessionHasErrors(['cedula' => BloqueoDePacientes::AVISO]);
        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());

        // El bloqueo es sólo por cédula: con otra cédula y el mismo teléfono y correo de la cuenta bloqueada se
        // reconoce a otra persona y se le manda el código.
        $this->post(route('reservas.codigo.enviar', $this->medico), ['cedula' => '99999999', 'email' => $cuenta->email] + $datos)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('reservas.codigo', $this->medico));

        // Lo bloquean entre el código y la confirmación: tampoco se crea la cita.
        app(BloqueoDePacientes::class)->desbloquear($cuenta);
        $this->post(route('reservas.codigo.enviar', $this->medico), $datos)
            ->assertRedirect(route('reservas.codigo', $this->medico));
        $codigo = (string) session('reservas.pendiente.codigo');
        $this->bloquear($cuenta);
        $this->post(route('reservas.confirmar', $this->medico), ['codigo' => $codigo]);
        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());

        // Desbloqueada, reserva.
        app(BloqueoDePacientes::class)->desbloquear($cuenta);
        $this->post(route('reservas.codigo.enviar', $this->medico), $datos)
            ->assertRedirect(route('reservas.codigo', $this->medico));
        $this->post(route('reservas.confirmar', $this->medico), ['codigo' => (string) session('reservas.pendiente.codigo')])
            ->assertRedirect(route('reservas.confirmada'));
        $this->assertSame(1, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_el_chatbot_no_agenda_a_un_paciente_bloqueado_y_si_a_uno_desbloqueado(): void
    {
        $cuenta = $this->cuentaDePaciente('12345008', '584141110008');
        $this->bloquear($cuenta);

        $servicio = app(PacienteAtencionContextService::class);
        $resultado = $servicio->reservarCitaEnCola('584141110008', $this->medico->reg_medico, $this->fecha->toDateString());

        $this->assertFalse($resultado['exito']);
        $this->assertTrue($resultado['bloqueado']);
        $this->assertSame(BloqueoDePacientes::AVISO, $resultado['mensaje']);
        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());

        app(BloqueoDePacientes::class)->desbloquear($cuenta);
        $resultado = $servicio->reservarCitaEnCola('584141110008', $this->medico->reg_medico, $this->fecha->toDateString());

        $this->assertTrue($resultado['exito'], $resultado['mensaje'] ?? '');
        $this->assertSame(1, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_la_regla_no_confunde_a_otros_pacientes_ni_a_cuentas_activas(): void
    {
        $bloqueada = $this->cuentaDePaciente('12345009', '584141110009');
        $this->cuentaDePaciente('12345010', '584141110010');
        $this->bloquear($bloqueada);

        $regla = app(BloqueoDePacientes::class);

        $this->assertNull($regla->cuentaBloqueada('12345010'));
        $this->assertNull($regla->cuentaBloqueada(null));
        $this->assertSame($bloqueada->id, $regla->cuentaBloqueada('V-12.345.009')?->id);
        $this->assertSame($bloqueada->id, $regla->cuentaBloqueada('12345009')?->id);
    }

    public function test_un_telefono_o_correo_igual_con_otra_cedula_no_se_bloquea(): void
    {
        $bloqueada = $this->cuentaDePaciente('12345011', '584141110011');
        $this->bloquear($bloqueada);

        $regla = app(BloqueoDePacientes::class);

        // La regla ya no recibe teléfono ni correo: sólo la cédula decide.
        $this->assertNull($regla->cuentaBloqueada('77777777'));

        // El chatbot evalúa la cédula de la ficha del teléfono: otra ficha, otra cédula, no se bloquea.
        Paciente::create(['cedula' => '77777777', 'nombres' => 'Otra', 'apellidos' => 'Persona', 'telefono' => '584141119999']);
        $resultado = app(PacienteAtencionContextService::class)
            ->reservarCitaEnCola('584141119999', $this->medico->reg_medico, $this->fecha->toDateString());
        $this->assertArrayNotHasKey('bloqueado', $resultado);
    }
}
