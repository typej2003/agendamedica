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
        $admin = User::create([
            'name' => 'Admin Solicitudes',
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => Hash::make('Secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']));

        return $admin;
    }

    public function test_el_formulario_es_publico_y_el_login_lo_enlaza(): void
    {
        $this->get(route('medico.registro'))
            ->assertOk()
            ->assertSee('Regístrate como médico')
            ->assertSee('Continuar y verificar mi correo');

        $this->get(route('login'))->assertSee(route('medico.registro'));
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

    public function test_un_correo_con_cuenta_no_puede_solicitar(): void
    {
        $admin = $this->administrador();

        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['email' => strtoupper($admin->email)]))
            ->assertSessionHasErrors('email');

        Mail::assertNotSent(CodigoDeRegistroDeMedico::class);
    }

    public function test_no_se_duplica_una_solicitud_en_revision_pero_si_tras_un_rechazo(): void
    {
        $primera = $this->solicitar();

        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['cedula' => $primera->cedula]))
            ->assertSessionHasErrors('email');

        $primera->update(['estado' => SolicitudMedico::RECHAZADA, 'motivo_rechazo' => 'Datos incompletos']);

        $this->post(route('medico.registro.codigo.enviar'), $this->datos(['cedula' => $primera->cedula]))
            ->assertRedirect(route('medico.registro.codigo'));
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
        $especialidad = Specialty::where('activo', true)->first() ?? Specialty::create(['name' => 'Especialidad de prueba', 'activo' => true]);
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
