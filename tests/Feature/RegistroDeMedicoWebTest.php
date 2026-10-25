<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\SolicitudesDeMedicos;
use App\Mail\RegistroDeMedico\CodigoDeRegistroDeMedico;
use App\Mail\RegistroDeMedico\NuevaSolicitudDeMedico;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoAprobada;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoRechazada;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoRecibida;
use App\Models\Medico;
use App\Models\RegMedicoServicio;
use App\Models\SolicitudMedico;
use App\Models\Specialty;
use App\Models\User;
use App\RegistroDeMedicos\CarnetDeLaSolicitud;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Registro de médicos con aprobación** (ROADMAP §10.4, fase 2).
 *
 * Lo que se fija acá: la solicitud nace sólo con el correo verificado y no crea cuenta; los duplicados se
 * rechazan antes de escribir; al aprobar nace la cuenta (o se le da acceso a la ficha del escritorio) con la
 * especialidad y el mes de prueba; al rechazar queda el motivo; y la bandeja y el carnet son sólo del admin.
 */
class RegistroDeMedicoWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
        Storage::fake(CarnetDeLaSolicitud::DISCO);
    }

    /** @return array<string,string> */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'prefijo' => 'Dra.',
            'nombres' => 'Lucía',
            'apellidos' => 'Prueba',
            'cedula' => '8' . random_int(1000000, 9999999),
            'mpps' => (string) random_int(100000, 999999),
            'telefono' => '0414-5556677',
            'email' => 'medico-' . uniqid() . '@example.com',
        ], $cambios);
    }

    private function codigo(): string
    {
        return (string) session('registro_medico.pendiente.codigo');
    }

    /** Manda el formulario y confirma el código: deja una solicitud pendiente. */
    private function solicitar(array $cambios = []): SolicitudMedico
    {
        $datos = $this->datos($cambios);
        $this->post(route('medico.registro.codigo.enviar'), $datos)->assertRedirect(route('medico.registro.codigo'));
        $this->post(route('medico.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('medico.registro.recibida'));

        return SolicitudMedico::where('email', $datos['email'])->firstOrFail();
    }

    private function administrador(): User
    {
        return $this->cuenta('Administrador');
    }

    /** Una cuenta de `users` con ese rol (paciente, administración, secretaría…). */
    private function cuenta(string $rol, string $clave = 'Secreto123'): User
    {
        $user = User::create([
            'name' => 'Cuenta ' . $rol,
            'email' => mb_strtolower($rol) . '-' . uniqid() . '@example.com',
            'password' => Hash::make($clave),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']));

        return $user;
    }

    /** Una ficha de médico **con** cuenta: ese correo ya no puede registrarse otra vez. */
    private function medicoConCuenta(): Medico
    {
        $user = $this->cuenta('Medico');

        return Medico::create([
            'user_id' => $user->id,
            'name' => 'Doc',
            'lastname' => 'Prueba',
            'email' => $user->email,
            'reg_medico' => 'M' . random_int(100000, 999999),
            'is_active' => true,
        ]);
    }

    public function test_el_formulario_es_publico_y_el_login_lo_enlaza(): void
    {
        $this->get(route('medico.registro'))
            ->assertOk()
            ->assertSee('Regístrate como médico')
            ->assertSee('Continuar y verificar mi correo');

        $this->get(route('login'))->assertSee(route('medico.registro'));
    }

    public function test_el_formulario_solo_ofrece_las_especialidades_soportadas(): void
    {
        // El desplegable ofrece las que AppDDR ya atiende (las que tienen manifiesto); el resto del catálogo
        // todavía no se ofrece al registrarse.
        $this->get(route('medico.registro'))
            ->assertOk()
            ->assertSee('Ginecología y Obstetricia')
            ->assertSee('Pediatría')
            ->assertDontSee('Cardiología')
            ->assertDontSee('Dermatología')
            ->assertDontSee('Medicina General');
    }

    public function test_una_especialidad_que_todavia_no_se_ofrece_se_rechaza(): void
    {
        $cardiologia = Specialty::where('name', 'Cardiología')->firstOrFail();

        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['specialty_id' => $cardiologia->id]))
            ->assertSessionHasErrors('specialty_id');

        Mail::assertNotSent(CodigoDeRegistroDeMedico::class);
    }

    public function test_una_especialidad_soportada_se_guarda_en_la_solicitud(): void
    {
        $pediatria = Specialty::where('name', 'Pediatría')->firstOrFail();

        $solicitud = $this->solicitar(['specialty_id' => $pediatria->id]);

        $this->assertSame($pediatria->id, (int) $solicitud->specialty_id);
    }

    public function test_el_codigo_va_al_correo_y_la_solicitud_nace_pendiente_sin_cuenta(): void
    {
        $datos = $this->datos();

        $this->post(route('medico.registro.codigo.enviar'), $datos)
            ->assertRedirect(route('medico.registro.codigo'));

        Mail::assertSent(CodigoDeRegistroDeMedico::class, fn ($m) => $m->hasTo($datos['email']) && $m->codigo === $this->codigo());
        $this->assertDatabaseMissing('solicitudes_medico', ['email' => $datos['email']]);

        $this->post(route('medico.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('medico.registro.recibida'));

        $this->assertDatabaseHas('solicitudes_medico', [
            'email' => $datos['email'],
            'cedula' => $datos['cedula'],
            'estado' => SolicitudMedico::PENDIENTE,
            'telefono' => '584145556677',
        ]);
        $this->assertDatabaseMissing('users', ['email' => $datos['email']]);
        $this->assertDatabaseMissing('medicos', ['email' => $datos['email']]);
        $this->assertGuest();

        Mail::assertSent(SolicitudDeMedicoRecibida::class, fn ($m) => $m->hasTo($datos['email']));
        $this->get(route('medico.registro.recibida'))->assertOk()->assertSee($datos['email']);
    }

    public function test_los_administradores_reciben_el_aviso(): void
    {
        $admin = $this->administrador();

        $this->solicitar();

        Mail::assertSent(NuevaSolicitudDeMedico::class, fn ($m) => $m->hasTo($admin->email));
    }

    public function test_un_codigo_equivocado_no_guarda_la_solicitud(): void
    {
        $datos = $this->datos();
        $this->post(route('medico.registro.codigo.enviar'), $datos);
        $bueno = $this->codigo();

        $this->post(route('medico.registro.confirmar'), ['codigo' => $bueno === '000000' ? '111111' : '000000'])
            ->assertSessionHasErrors('codigo');

        $this->assertDatabaseMissing('solicitudes_medico', ['email' => $datos['email']]);
    }

    public function test_un_correo_que_ya_es_de_medico_no_puede_solicitar(): void
    {
        $medico = $this->medicoConCuenta();

        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['email' => strtoupper($medico->email)]))
            ->assertSessionHasErrors('email');

        Mail::assertNotSent(CodigoDeRegistroDeMedico::class);
    }

    public function test_una_cuenta_que_no_es_de_medico_si_puede_registrarse(): void
    {
        // El mismo correo de un paciente (o de administración, o de la secretaría) puede pedir el área médica:
        // es lo que hacía imposible que un paciente se volviera médico (2026-10-24).
        foreach (['Paciente', 'Administrador', 'Secretaria'] as $rol) {
            $cuenta = $this->cuenta($rol);

            $this->post(route('medico.registro.codigo.enviar'), $this->datos(['email' => $cuenta->email]))
                ->assertRedirect(route('medico.registro.codigo'));
        }
    }

    public function test_aprobar_una_cuenta_existente_le_habilita_el_area_medica_sin_tocarle_la_clave(): void
    {
        $paciente = $this->cuenta('Paciente');
        $solicitud = $this->solicitar(['email' => $paciente->email]);
        $reg = 'MPPS-' . $solicitud->mpps;

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->set('reg_medico', $reg)
            ->call('aprobar')
            ->assertHasNoErrors()
            ->assertSet('claveGenerada.clave', null)
            ->assertSet('claveGenerada.correoEnviado', true)
            ->assertSee('se habilitó su área médica');

        $paciente->refresh();

        $this->assertSame(1, User::where('email', $paciente->email)->count());
        $this->assertTrue($paciente->hasRole('Paciente'));
        $this->assertTrue($paciente->hasRole('Medico'));
        $this->assertSame($reg, $paciente->medico->reg_medico);
        $this->assertFalse((bool) $paciente->must_change_password);
        $this->assertTrue(Hash::check('Secreto123', $paciente->password));

        // Con dos áreas, el login le pregunta dónde entrar.
        $this->assertSame('elegir-panel', $paciente->rutaDeEntrada());

        // El correo de la aprobación sale sin clave temporal.
        Mail::assertSent(
            SolicitudDeMedicoAprobada::class,
            fn ($m) => $m->hasTo($paciente->email) && $m->clave === null
        );
    }

    public function test_la_cedula_de_una_solicitud_en_revision_no_se_repite_con_otro_correo(): void
    {
        $primera = $this->solicitar();

        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['cedula' => $primera->cedula]))
            ->assertSessionHasErrors('cedula');

        $primera->update(['estado' => SolicitudMedico::RECHAZADA, 'motivo_rechazo' => 'Datos incompletos']);

        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['cedula' => $primera->cedula]))
            ->assertRedirect(route('medico.registro.codigo'));
    }

    public function test_una_solicitud_pendiente_se_retoma_y_se_corrige_en_vez_de_bloquear(): void
    {
        $primera = $this->solicitar();

        // El mismo correo vuelve a verificar su dirección y corrige sus datos: no se le niega el código ni se
        // crea una segunda solicitud (antes quedaba atado a la primera y no podía hacer nada).
        $this->post(route('medico.registro.codigo.enviar'), $this->datos([
            'email' => $primera->email,
            'cedula' => $primera->cedula,
            'nombres' => 'Corregido',
        ]))->assertRedirect(route('medico.registro.codigo'));

        $this->post(route('medico.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('medico.registro.recibida'));

        $this->assertSame(1, SolicitudMedico::where('email', $primera->email)->count());
        $this->assertSame('Corregido', $primera->fresh()->nombres);
    }

    public function test_una_ficha_del_escritorio_sin_cuenta_no_bloquea_el_registro(): void
    {
        $email = 'ficha-' . uniqid() . '@example.com';
        Medico::create([
            'name' => 'Ana',
            'lastname' => 'Ficha',
            'reg_medico' => 'F' . random_int(100000, 999999),
            'email' => $email,
            'is_active' => true,
        ]);

        // La cuenta no existe todavía: al aprobar, el administrador le da acceso a esa misma ficha.
        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['email' => $email]))
            ->assertRedirect(route('medico.registro.codigo'));
    }

    public function test_la_foto_del_carnet_sobrevive_a_un_campo_con_error(): void
    {
        $datos = $this->datos(['telefono' => '']);

        // Falla el teléfono, pero la foto ya quedó guardada.
        $this->post(route('medico.registro.codigo.enviar'), array_merge($datos, [
            'carnet' => UploadedFile::fake()->image('carnet.png', 400, 250),
        ]))->assertSessionHasErrors('telefono');

        $ruta = session('registro_medico.carnet.carnet_ruta');
        $this->assertNotNull($ruta);
        Storage::disk(CarnetDeLaSolicitud::DISCO)->assertExists($ruta);

        // Al corregir y reenviar sin volver a elegirla, la solicitud viaja con la misma foto.
        $this->post(route('medico.registro.codigo.enviar'), $this->datos([
            'email' => $datos['email'],
            'cedula' => $datos['cedula'],
        ]))->assertRedirect(route('medico.registro.codigo'));

        $this->post(route('medico.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('medico.registro.recibida'));

        $solicitud = SolicitudMedico::where('email', $datos['email'])->firstOrFail();
        $this->assertSame($ruta, $solicitud->carnet_ruta);
        Storage::disk(CarnetDeLaSolicitud::DISCO)->assertExists($solicitud->carnet_ruta);
    }

    public function test_la_foto_del_carnet_se_guarda_en_el_disco_privado(): void
    {
        $solicitud = $this->solicitar(['carnet' => UploadedFile::fake()->image('carnet.png', 400, 250)]);

        $this->assertNotNull($solicitud->carnet_ruta);
        Storage::disk(CarnetDeLaSolicitud::DISCO)->assertExists($solicitud->carnet_ruta);

        // Sólo la ve un administrador.
        $this->get(route('admin.solicitudes-medicos.carnet', $solicitud->id))->assertRedirect(route('login'));
        $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes-medicos.carnet', $solicitud->id))
            ->assertOk();
    }

    public function test_aprobar_crea_la_cuenta_con_especialidad_y_prueba_y_avisa(): void
    {
        $especialidad = Specialty::where('slug', 'pediatria')->firstOrFail();
        $solicitud = $this->solicitar(['specialty_id' => $especialidad->id]);
        $admin = $this->administrador();
        $reg = 'MPPS-' . $solicitud->mpps;

        $this->actingAs($admin);
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->assertSet('reg_medico', $reg)
            ->assertSet('specialty_id', (string) $especialidad->id)
            ->call('aprobar')
            ->assertHasNoErrors()
            ->assertSet('claveGenerada.correoEnviado', true);

        $solicitud->refresh();
        $medico = Medico::where('reg_medico', $reg)->firstOrFail();
        $user = User::where('email', $solicitud->email)->firstOrFail();

        $this->assertSame(SolicitudMedico::APROBADA, $solicitud->estado);
        $this->assertSame($medico->id, (int) $solicitud->medico_id);
        $this->assertSame($admin->id, (int) $solicitud->revisada_por);
        $this->assertSame($user->id, (int) $medico->user_id);
        $this->assertTrue($user->hasRole('Medico'));
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertSame($solicitud->mpps, $medico->license_number);
        $this->assertTrue($medico->specialties()->whereKey($especialidad->id)->exists());

        Mail::assertSent(SolicitudDeMedicoAprobada::class, function ($m) use ($solicitud, $user) {
            return $m->hasTo($solicitud->email) && Hash::check($m->clave, $user->password);
        });

        // El mes de prueba sale del evento `MedicoRegistrado`, si hay un plan por defecto.
        if (\App\Models\Plan::porDefecto()) {
            $this->assertTrue(RegMedicoServicio::where('reg_medico', $reg)->exists());
        }
    }

    public function test_aprobar_avisa_si_el_correo_es_de_una_ficha_sin_cuenta_con_otro_reg_medico(): void
    {
        $email = 'ficha-' . uniqid() . '@example.com';
        $regFicha = 'F' . random_int(100000, 999999);
        Medico::create([
            'name' => 'Ana',
            'lastname' => 'Ficha',
            'reg_medico' => $regFicha,
            'email' => $email,
            'is_active' => true,
        ]);

        $solicitud = $this->solicitar(['email' => $email]);

        // El administrador confirma un reg_medico nuevo: se le dice cuál es el de la ficha, en vez de dejarlo
        // con el «Ya existe una cuenta con ese correo» de `crearMedico`.
        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->call('aprobar')
            ->assertHasErrors('reg_medico');

        $this->assertSame(SolicitudMedico::PENDIENTE, $solicitud->fresh()->estado);
    }

    public function test_aprobar_con_el_reg_medico_de_una_ficha_sin_cuenta_le_da_acceso_a_esa_ficha(): void
    {
        $solicitud = $this->solicitar();
        $reg = 'escritorio-' . uniqid();
        $ficha = Medico::create(['name' => 'Lucía', 'lastname' => 'Prueba', 'reg_medico' => $reg, 'is_active' => true, 'phone' => '0212-0000000']);

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->set('reg_medico', $reg)
            ->call('aprobar')
            ->assertHasNoErrors();

        $ficha->refresh();
        $this->assertSame(1, Medico::where('reg_medico', $reg)->count());
        $this->assertNotNull($ficha->user_id);
        $this->assertSame($solicitud->email, $ficha->email);
        // Lo que vino del escritorio no se pisa.
        $this->assertSame('0212-0000000', $ficha->phone);
        $this->assertSame($ficha->id, (int) $solicitud->fresh()->medico_id);
    }

    public function test_no_se_aprueba_sobre_la_ficha_de_otro_correo_ni_dos_veces(): void
    {
        $solicitud = $this->solicitar();
        $reg = 'escritorio-' . uniqid();
        Medico::create(['name' => 'Otro', 'lastname' => 'Médico', 'reg_medico' => $reg, 'email' => 'otro-' . uniqid() . '@example.com', 'is_active' => true]);

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->set('reg_medico', $reg)
            ->call('aprobar')
            ->assertHasErrors('reg_medico');

        $this->assertTrue($solicitud->fresh()->estaPendiente());
        $this->assertDatabaseMissing('users', ['email' => $solicitud->email]);

        $componente = Livewire::test(SolicitudesDeMedicos::class)->call('abrir', $solicitud->id)->call('aprobar')->assertHasNoErrors();
        $componente->call('abrir', $solicitud->id)->call('aprobar')->assertHasErrors('solicitud');
    }

    public function test_rechazar_guarda_el_motivo_y_avisa(): void
    {
        $solicitud = $this->solicitar();

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->call('rechazar')
            ->assertHasErrors('motivo')
            ->set('motivo', 'El número del MPPS no coincide con la cédula.')
            ->call('rechazar')
            ->assertHasNoErrors();

        $solicitud->refresh();
        $this->assertSame(SolicitudMedico::RECHAZADA, $solicitud->estado);
        $this->assertSame('El número del MPPS no coincide con la cédula.', $solicitud->motivo_rechazo);
        $this->assertDatabaseMissing('users', ['email' => $solicitud->email]);
        Mail::assertSent(SolicitudDeMedicoRechazada::class, fn ($m) => $m->hasTo($solicitud->email));
    }

    public function test_la_bandeja_es_solo_de_administradores(): void
    {
        $solicitud = $this->solicitar();

        $this->get(route('admin.solicitudes-medicos'))->assertRedirect(route('login'));

        $medico = User::create([
            'name' => 'Médico sin panel',
            'email' => 'medico-panel-' . uniqid() . '@example.com',
            'password' => Hash::make('Secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $medico->assignRole(Role::firstOrCreate(['name' => 'Medico', 'guard_name' => 'web']));

        $this->actingAs($medico)->get(route('admin.solicitudes-medicos'))->assertForbidden();

        $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes-medicos'))
            ->assertOk()
            ->assertSee($solicitud->nombreCompleto());
    }
}
