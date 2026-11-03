<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\DetalleDelMedico;
use App\Http\Livewire\Admin\SolicitudesDeMedicos;
use App\Mail\RegistroDeMedico\MedicoRegistradoSinVerificar;
use App\Mail\RegistroDeMedico\NuevaSolicitudDeMedico;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoAprobada;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoRecibida;
use App\Models\ConfiguracionDeVerificacion;
use App\Models\Medico;
use App\Models\SolicitudMedico;
use App\Models\User;
use App\Models\VerificacionDeMedico;
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
 * **Verificación de médicos** (2026-11-03): el carnet deja de ser un impedimento para usar la app, pero queda
 * guardado en el perfil —junto con el correo y el teléfono, cada uno por separado— y el administrador puede
 * validarlo después. El interruptor de `/admin/solicitudes-medicos` decide si se exige.
 *
 * Lo que se fija acá:
 *  - con la exigencia **apagada** (de fábrica), el registro se aprueba solo al confirmar el correo y deja las
 *    tres verificaciones en su estado inicial;
 *  - con la exigencia **encendida**, el registro espera al administrador y el médico sin carnet verificado no
 *    entra (web y app);
 *  - el carnet se puede verificar o rechazar después; el rechazo corta el acceso siempre;
 *  - si el `reg_medico` propuesto choca con otra ficha, no se crea nada y la solicitud queda pendiente.
 */
class VerificacionDeMedicosTest extends TestCase
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
            'terminos' => '1',
        ], $cambios);
    }

    private function codigo(): string
    {
        return (string) session('registro_medico.pendiente.codigo');
    }

    /** Manda el formulario y confirma el código. Con la política apagada deja la solicitud aprobada sola. */
    private function solicitar(array $cambios = []): SolicitudMedico
    {
        $datos = $this->datos($cambios);

        $this->post(route('medico.registro.codigo.enviar'), $datos)->assertRedirect(route('medico.registro.codigo'));
        $this->post(route('medico.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('medico.registro.recibida'));

        return SolicitudMedico::where('email', $datos['email'])->firstOrFail();
    }

    private function exigirCarnet(bool $exigir = true): void
    {
        ConfiguracionDeVerificacion::actual()->update(['exigir_carnet' => $exigir]);
    }

    private function administrador(): User
    {
        $user = User::create([
            'name' => 'Administración',
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => Hash::make('Secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']));

        return $user;
    }

    /** La clave temporal que salió en el correo de la aprobación. */
    private function claveTemporal(): string
    {
        return (string) Mail::sent(SolicitudDeMedicoAprobada::class)->last()->clave;
    }

    /** Cierra la sesión del administrador que abrió la bandeja, para probar el login del médico limpio. */
    private function cerrarSesion(): void
    {
        $this->post('/logout');
    }

    /** La ficha y la cuenta que nacieron del registro. */
    private function medicoDe(SolicitudMedico $solicitud): Medico
    {
        return Medico::where('email', $solicitud->email)->firstOrFail();
    }

    public function test_con_la_exigencia_apagada_el_registro_se_aprueba_solo_y_deja_las_tres_verificaciones(): void
    {
        $solicitud = $this->solicitar();

        $solicitud->refresh();
        $medico = $this->medicoDe($solicitud);
        $user = User::where('email', $solicitud->email)->firstOrFail();

        $this->assertSame(SolicitudMedico::APROBADA, $solicitud->estado);
        $this->assertTrue((bool) $solicitud->aprobada_automaticamente);
        $this->assertNull($solicitud->revisada_por);
        $this->assertSame($medico->id, (int) $solicitud->medico_id);
        $this->assertSame($user->id, (int) $medico->user_id);
        $this->assertTrue($user->hasRole('Medico'));

        $this->assertSame(VerificacionDeMedico::VERIFICADO, $medico->estadoDeVerificacion(VerificacionDeMedico::CORREO));
        $this->assertSame(VerificacionDeMedico::SIN_VERIFICAR, $medico->estadoDeVerificacion(VerificacionDeMedico::CARNET));
        $this->assertSame(VerificacionDeMedico::SIN_VERIFICAR, $medico->estadoDeVerificacion(VerificacionDeMedico::TELEFONO));

        Mail::assertSent(SolicitudDeMedicoAprobada::class, fn ($m) => $m->hasTo($solicitud->email));
        Mail::assertSent(MedicoRegistradoSinVerificar::class);
        Mail::assertNotSent(SolicitudDeMedicoRecibida::class);

        $this->get(route('medico.registro.recibida'))->assertOk()->assertSee('Tu cuenta está lista');
    }

    public function test_si_adjunto_el_carnet_queda_pendiente_de_revision(): void
    {
        $solicitud = $this->solicitar(['carnet' => UploadedFile::fake()->image('carnet.png', 400, 250)]);

        $this->assertNotNull($solicitud->carnet_ruta);
        $this->assertSame(
            VerificacionDeMedico::PENDIENTE,
            $this->medicoDe($solicitud)->estadoDeVerificacion(VerificacionDeMedico::CARNET),
        );
    }

    public function test_con_la_exigencia_encendida_el_registro_espera_al_administrador(): void
    {
        $this->exigirCarnet();

        $solicitud = $this->solicitar();

        $this->assertTrue($solicitud->estaPendiente());
        $this->assertFalse((bool) $solicitud->aprobada_automaticamente);
        $this->assertDatabaseMissing('users', ['email' => $solicitud->email]);
        $this->assertDatabaseMissing('medicos', ['email' => $solicitud->email]);
        $this->assertNull($solicitud->medico_id);

        Mail::assertSent(SolicitudDeMedicoRecibida::class, fn ($m) => $m->hasTo($solicitud->email));
        Mail::assertSent(NuevaSolicitudDeMedico::class);
        Mail::assertNotSent(SolicitudDeMedicoAprobada::class);
    }

    public function test_el_administrador_aprueba_dando_el_carnet_por_verificado_y_el_medico_entra(): void
    {
        $this->exigirCarnet();
        $solicitud = $this->solicitar(['carnet' => UploadedFile::fake()->image('carnet.png', 400, 250)]);

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->set('carnet_verificado', true)
            ->call('aprobar')
            ->assertHasNoErrors();

        $medico = $this->medicoDe($solicitud->fresh());
        $this->assertSame(VerificacionDeMedico::VERIFICADO, $medico->estadoDeVerificacion(VerificacionDeMedico::CARNET));
        $this->assertNotNull($medico->user);

        // Sin la clave temporal de por medio, el login web deja pasar.
        $this->cerrarSesion();
        $medico->user->forceFill(['must_change_password' => false])->save();
        $this->post('/login', [
            'email' => $solicitud->email,
            'password' => $this->claveTemporal(),
        ])->assertRedirect(route('clinica.inicio'));
    }

    public function test_con_la_exigencia_encendida_un_carnet_sin_verificar_corta_el_login_y_el_app(): void
    {
        $this->exigirCarnet();
        $solicitud = $this->solicitar(['carnet' => UploadedFile::fake()->image('carnet.png', 400, 250)]);
        $clave = $this->aprobarSinVerificar($solicitud);
        $this->cerrarSesion();

        // Web: el login no entra y deja el motivo.
        $this->post('/login', ['email' => $solicitud->email, 'password' => $clave])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // App: 403 con el código que espera el teléfono.
        $this->postJson('/api/app/login', ['email' => $solicitud->email, 'password' => $clave])
            ->assertStatus(403)
            ->assertJsonPath('code', 'verificacion_pendiente');

        // Una sesión que ya estaba abierta también se corta en `/clinica`.
        $medico = $this->medicoDe($solicitud->fresh());
        $medico->user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($medico->user)->get(route('clinica.contexto'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_el_administrador_verifica_el_carnet_despues_y_el_medico_entra(): void
    {
        $this->exigirCarnet();
        $solicitud = $this->solicitar(['carnet' => UploadedFile::fake()->image('carnet.png', 400, 250)]);
        $clave = $this->aprobarSinVerificar($solicitud);

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->call('marcarCarnet', VerificacionDeMedico::VERIFICADO)
            ->assertHasNoErrors();
        $this->cerrarSesion();

        $medico = $this->medicoDe($solicitud->fresh());
        $this->assertTrue($medico->carnetVerificado());

        $medico->user->forceFill(['must_change_password' => false])->save();
        $this->post('/login', ['email' => $solicitud->email, 'password' => $clave])
            ->assertRedirect(route('clinica.inicio'));
    }

    public function test_el_carnet_rechazado_corta_el_acceso_aunque_la_exigencia_este_apagada(): void
    {
        $solicitud = $this->solicitar();
        $medico = $this->medicoDe($solicitud);
        $clave = $this->claveTemporal();
        $medico->user->forceFill(['must_change_password' => false])->save();

        // Con la exigencia apagada entra.
        $this->post('/login', ['email' => $solicitud->email, 'password' => $clave])
            ->assertRedirect(route('clinica.inicio'));
        $this->cerrarSesion();

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->call('marcarCarnet', VerificacionDeMedico::RECHAZADO)
            ->assertHasNoErrors();
        $this->cerrarSesion();

        $this->post('/login', ['email' => $solicitud->email, 'password' => $clave])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_el_interruptor_se_guarda_desde_la_bandeja(): void
    {
        $this->assertFalse(ConfiguracionDeVerificacion::actual()->exigeCarnet());

        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->assertSet('exigir_carnet', false)
            ->set('exigir_carnet', true)
            ->call('guardarPolitica')
            ->assertHasNoErrors();

        $this->assertTrue(ConfiguracionDeVerificacion::actual()->fresh()->exigeCarnet());
    }

    public function test_si_el_reg_medico_propuesto_ya_es_de_otro_correo_la_solicitud_queda_pendiente(): void
    {
        $datos = $this->datos();
        Medico::create([
            'name' => 'Otro',
            'lastname' => 'Médico',
            'reg_medico' => 'MPPS-' . $datos['mpps'],
            'email' => 'otro-' . uniqid() . '@example.com',
            'is_active' => true,
        ]);

        $this->post(route('medico.registro.codigo.enviar'), $datos)->assertRedirect(route('medico.registro.codigo'));
        $this->post(route('medico.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('medico.registro.recibida'));

        $solicitud = SolicitudMedico::where('email', $datos['email'])->firstOrFail();
        $this->assertTrue($solicitud->estaPendiente());
        $this->assertNull($solicitud->medico_id);
        $this->assertDatabaseMissing('users', ['email' => $datos['email']]);
        Mail::assertSent(NuevaSolicitudDeMedico::class);
    }

    public function test_una_ficha_del_escritorio_del_mismo_correo_recibe_el_acceso_sola(): void
    {
        $datos = $this->datos();
        $ficha = Medico::create([
            'name' => 'Lucía',
            'lastname' => 'Prueba',
            'reg_medico' => 'MPPS-' . $datos['mpps'],
            'email' => $datos['email'],
            'is_active' => true,
        ]);

        $this->solicitar($datos);

        $ficha->refresh();
        $this->assertNotNull($ficha->user_id);
        $this->assertSame(1, Medico::where('email', $datos['email'])->count());
        $this->assertSame(
            VerificacionDeMedico::VERIFICADO,
            $ficha->estadoDeVerificacion(VerificacionDeMedico::CORREO),
        );
    }

    public function test_un_correo_que_ya_es_de_paciente_recibe_el_area_medica_en_su_misma_cuenta(): void
    {
        $cuenta = User::create([
            'name' => 'Paciente',
            'email' => 'paciente-' . uniqid() . '@example.com',
            'password' => Hash::make('Secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $cuenta->assignRole(Role::firstOrCreate(['name' => 'Paciente', 'guard_name' => 'web']));

        $solicitud = $this->solicitar(['email' => $cuenta->email]);

        $cuenta->refresh();
        $this->assertSame(1, User::where('email', $cuenta->email)->count());
        $this->assertTrue($cuenta->hasRole('Paciente'));
        $this->assertTrue($cuenta->hasRole('Medico'));
        $this->assertSame('MPPS-' . $solicitud->mpps, $cuenta->medico->reg_medico);
        $this->assertFalse((bool) $cuenta->must_change_password);

        Mail::assertSent(SolicitudDeMedicoAprobada::class, fn ($m) => $m->hasTo($cuenta->email) && $m->clave === null);
    }

    public function test_el_administrador_verifica_el_carnet_de_un_medico_del_escritorio_desde_su_detalle(): void
    {
        $this->exigirCarnet();

        $clave = 'Secreto123';
        $user = User::create([
            'name' => 'Doc Escritorio',
            'email' => 'escritorio-' . uniqid() . '@example.com',
            'password' => Hash::make($clave),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Medico', 'guard_name' => 'web']));
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doc',
            'lastname' => 'Escritorio',
            'email' => $user->email,
            'reg_medico' => 'MPPS-LEGACY-' . random_int(1000, 9999),
            'is_active' => true,
        ]);

        // Sin carnet verificado, el médico del escritorio no entra mientras la exigencia esté encendida.
        $this->post('/login', ['email' => $user->email, 'password' => $clave])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($this->administrador());
        Livewire::test(DetalleDelMedico::class, ['medico' => $medico])
            ->call('marcarCarnet', VerificacionDeMedico::VERIFICADO)
            ->assertHasNoErrors();
        $this->cerrarSesion();

        $this->assertTrue($medico->fresh()->carnetVerificado());
        $this->post('/login', ['email' => $user->email, 'password' => $clave])
            ->assertRedirect(route('clinica.inicio'));
    }

    public function test_la_bandeja_muestra_el_interruptor_y_las_tres_verificaciones_y_el_detalle_del_medico_tambien(): void
    {
        $solicitud = $this->solicitar();
        $medico = $this->medicoDe($solicitud);
        $admin = $this->administrador();

        $this->actingAs($admin)->get(route('admin.solicitudes-medicos'))
            ->assertOk()
            ->assertSee('Verificación de médicos')
            ->assertSee('Exigir carnet verificado');

        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->assertSee('Carnet')
            ->assertSee('Correo')
            ->assertSee('Teléfono')
            ->assertSee('sin verificar')
            ->assertSee('Carnet verificado');

        $this->get(route('admin.cuentas.medico', $medico))
            ->assertOk()
            ->assertSee('Verificación')
            ->assertSee('Carnet verificado')
            ->assertSee('Rechazar carnet');
    }

    /** Aprueba con el administrador sin marcar el carnet y devuelve la clave temporal que salió por correo. */
    private function aprobarSinVerificar(SolicitudMedico $solicitud): string
    {
        $this->actingAs($this->administrador());
        Livewire::test(SolicitudesDeMedicos::class)
            ->call('abrir', $solicitud->id)
            ->assertSet('carnet_verificado', false)
            ->call('aprobar')
            ->assertHasNoErrors();

        return $this->claveTemporal();
    }
}
