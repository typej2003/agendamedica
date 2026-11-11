<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Cuentas;
use App\Models\Medico;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Borrar la cuenta de un doctor** (2026-11-10): la baja lógica.
 *
 * Se archiva y no se borra: la ficha y sus datos clínicos se conservan; lo que se libera es el correo (con el
 * que entra), el teléfono y el carnet. La pantalla es «Usuarios · Médicos» (`Admin\Cuentas`) y el detalle del
 * médico (`Admin\DetalleDelMedico`), y la lógica está en `CuentaService::archivarMedico()`.
 *
 * `DatabaseTransactions`: la base sqlite de desarrollo trae datos reales (ver MedicAPI/AGENTS.md).
 */
class ArchivoDeCuentaTest extends TestCase
{
    use DatabaseTransactions;

    private CuentaService $cuentas;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->cuentas = app(CuentaService::class);
    }

    /** Administrador que ya cambió su clave (si no, el middleware lo manda a /cambiar-password). */
    private function admin(): User
    {
        $user = $this->cuentas->crearAdministrador('Admin Archivo', 'admin-archivo-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /**
     * Un doctor con cuenta y con sus datos de contacto cargados. La clave es conocida para probar el login.
     *
     * @return array{user:User, medico:Medico, email:string, telefono:string, carnet:string, clave:string}
     */
    private function doctor(string $apellido = 'Archivable'): array
    {
        $sufijo = substr(uniqid(), -6);
        $email = 'archivo-' . $sufijo . '@example.com';
        $telefono = '+58 412-' . random_int(1000000, 9999999);
        $carnet = 'MPPS-' . random_int(10000, 99999);
        $clave = 'clave-' . $sufijo;

        $r = $this->cuentas->crearMedico([
            'name'           => 'Doctor',
            'lastname'       => $apellido,
            'email'          => $email,
            'reg_medico'     => 'test-arch-' . $sufijo,
            'phone'          => $telefono,
            'license_number' => $carnet,
        ], $clave);
        $r['user']->forceFill(['must_change_password' => false])->save();

        return [
            'user' => $r['user']->fresh(), 'medico' => $r['medico']->fresh(),
            'email' => $email, 'telefono' => $telefono, 'carnet' => $carnet, 'clave' => $clave,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* La baja                                                            */
    /* ------------------------------------------------------------------ */

    public function test_archivar_libera_el_correo_el_telefono_y_el_carnet_y_conserva_la_ficha(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor();
        $token = $doctor['user']->createToken('app')->plainTextToken;
        $this->assertNotEmpty($token);

        $archivado = $this->cuentas->archivarMedico($doctor['medico'], 'Dejó de trabajar acá', $actor);

        // La ficha sigue existiendo, con lo que la identifica guardado y lo que se libera vacío.
        $this->assertTrue($archivado->estaArchivado());
        $this->assertNotNull($archivado->archivado_en);
        $this->assertEquals($actor->id, $archivado->archivado_por_id);
        $this->assertSame('Dejó de trabajar acá', $archivado->archivado_motivo);
        $this->assertSame($doctor['email'], $archivado->email_original);
        $this->assertSame($doctor['telefono'], $archivado->telefono_original);
        $this->assertSame($doctor['carnet'], $archivado->carnet_original);
        $this->assertNull($archivado->email);
        $this->assertNull($archivado->phone);
        $this->assertNull($archivado->license_number);
        $this->assertFalse((bool) $archivado->is_active);

        // La cuenta de acceso: el correo verdadero queda libre y el de la cuenta se renombra.
        $cuenta = $doctor['user']->fresh();
        $this->assertSame($doctor['email'], $cuenta->email_original);
        $this->assertSame($this->cuentas->correoArchivado($cuenta), $cuenta->email);
        $this->assertStringContainsString('borrado+', (string) $cuenta->email);
        $this->assertFalse((bool) $cuenta->is_active);
        $this->assertNotNull($cuenta->archivado_en);
        $this->assertSame(0, $cuenta->tokens()->count(), 'Al archivar se cierran las sesiones del app.');

        // El correo, el teléfono y el carnet no figuran en ninguna otra ficha.
        $this->assertFalse(User::where('email', $doctor['email'])->exists());
        $this->assertFalse(Medico::where('email', $doctor['email'])->exists());
    }

    public function test_el_correo_liberado_se_puede_usar_en_otra_cuenta(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor();
        $this->cuentas->archivarMedico($doctor['medico'], '', $actor);

        // Mismo correo, otra ficha: es el punto de la baja (volver a contratar al doctor, o su reemplazo).
        $nuevo = $this->cuentas->crearMedico([
            'name' => 'Doctor', 'lastname' => 'Nuevo', 'email' => $doctor['email'], 'reg_medico' => 'test-reuso-' . substr(uniqid(), -6),
        ]);

        $this->assertSame($doctor['email'], $nuevo['user']->fresh()->email);
        $this->assertNotSame($doctor['medico']->id, $nuevo['medico']->id);
    }

    public function test_la_cuenta_archivada_queda_fuera(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor();

        // Antes de la baja la clave sirve (es el camino real del login web).
        $this->assertNotNull(app(\App\Services\AutenticacionPorCorreo::class)->autenticar($doctor['email'], $doctor['clave']));

        $this->cuentas->archivarMedico($doctor['medico'], '', $actor);

        // El correo verdadero se liberó: no queda con qué entrar por ese lado.
        $this->assertNull(app(\App\Services\AutenticacionPorCorreo::class)->autenticar($doctor['email'], $doctor['clave']));

        // Y aunque alguien probara con el correo marcador, la cuenta está inactiva: la web le cierra la sesión.
        $conMarcador = app(\App\Services\AutenticacionPorCorreo::class)->autenticar((string) $doctor['user']->fresh()->email, $doctor['clave']);
        $this->assertNotNull($conMarcador, 'La clave sigue siendo la misma: lo que corta el acceso es la cuenta inactiva.');
        $this->actingAs($conMarcador)->get('/dashboard')->assertRedirect(route('login'))->assertSessionHasErrors('email');
    }

    /* ------------------------------------------------------------------ */
    /* Lo que no se archiva                                               */
    /* ------------------------------------------------------------------ */

    public function test_no_se_archiva_un_centro_ni_un_consultorio(): void
    {
        $actor = $this->admin();
        $centro = $this->cuentas->crearCentro(['name' => 'Centro', 'lastname' => 'Prueba', 'reg_medico' => 'test-centro-' . substr(uniqid(), -6)]);

        try {
            $this->cuentas->archivarMedico($centro, '', $actor);
            $this->fail('Un centro médico no es una cuenta de doctor.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('centro médico', $e->validator->errors()->first());
        }

        $doctor = $this->doctor();
        $consultorio = $this->cuentas->agregarConsultorio($doctor['medico'], 'test-hijo-' . substr(uniqid(), -6));

        try {
            $this->cuentas->archivarMedico($consultorio, '', $actor);
            $this->fail('Un consultorio hijo tampoco es una cuenta de doctor.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('consultorio del médico', $e->validator->errors()->first());
        }

        $this->assertFalse($consultorio->fresh()->estaArchivado());
    }

    public function test_no_se_archiva_un_doctor_con_consultorios_colgando(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor();
        $this->cuentas->agregarConsultorio($doctor['medico'], 'test-cuelga-' . substr(uniqid(), -6));

        $this->expectException(ValidationException::class);
        $this->cuentas->archivarMedico($doctor['medico'], '', $actor);
    }

    public function test_no_se_archiva_una_cuenta_administradora_ni_la_propia(): void
    {
        $actor = $this->admin();

        // Un doctor que además es administrador: primero se le quita ese rol.
        $doctor = $this->doctor();
        $doctor['user']->assignRole('Administrador');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $this->cuentas->archivarMedico($doctor['medico'], '', $actor);
            $this->fail('Una cuenta con rol de administración no se archiva.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('administración', $e->validator->errors()->first());
        }

        // La propia cuenta: el administrador también puede tener ficha de médico.
        $propio = $this->cuentas->darRolDeMedico($actor, [
            'name' => 'Admin', 'lastname' => 'Propio', 'reg_medico' => 'test-propio-' . substr(uniqid(), -6),
        ]);

        try {
            $this->cuentas->archivarMedico($propio['medico'], '', $actor);
            $this->fail('Nadie borra su propia cuenta.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tu propia cuenta', $e->validator->errors()->first());
        }
    }

    public function test_archivar_dos_veces_la_misma_cuenta_lo_rechaza(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor();
        $this->cuentas->archivarMedico($doctor['medico'], '', $actor);

        $this->expectException(ValidationException::class);
        $this->cuentas->archivarMedico($doctor['medico']->fresh(), '', $actor);
    }

    /* ------------------------------------------------------------------ */
    /* Restaurar                                                          */
    /* ------------------------------------------------------------------ */

    public function test_restaurar_devuelve_los_datos_y_el_acceso(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor();
        $this->cuentas->archivarMedico($doctor['medico'], 'error', $actor);

        $r = $this->cuentas->restaurarMedico($doctor['medico']->fresh());

        $this->assertTrue($r['correo_restaurado']);
        $ficha = $doctor['medico']->fresh();
        $this->assertFalse($ficha->estaArchivado());
        $this->assertSame($doctor['email'], $ficha->email);
        $this->assertSame($doctor['telefono'], $ficha->phone);
        $this->assertSame($doctor['carnet'], $ficha->license_number);
        $this->assertTrue((bool) $ficha->is_active);
        $this->assertNull($ficha->email_original);

        $cuenta = $doctor['user']->fresh();
        $this->assertSame($doctor['email'], $cuenta->email);
        $this->assertTrue((bool) $cuenta->is_active);
        $this->assertNull($cuenta->archivado_en);
    }

    public function test_restaurar_con_el_correo_ya_tomado_avisa_y_no_se_lo_roba_a_la_otra_cuenta(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor();
        $this->cuentas->archivarMedico($doctor['medico'], '', $actor);

        // Otra cuenta se quedó con el correo mientras estaba archivada.
        $otro = $this->cuentas->crearMedico([
            'name' => 'Otro', 'lastname' => 'Doctor', 'email' => $doctor['email'], 'reg_medico' => 'test-ocupado-' . substr(uniqid(), -6),
        ]);

        $r = $this->cuentas->restaurarMedico($doctor['medico']->fresh());

        $this->assertFalse($r['correo_restaurado']);
        $this->assertSame($doctor['email'], $r['correo_original']);
        // La cuenta del otro no se toca; la restaurada queda con el correo marcador y su original guardado.
        $this->assertSame($doctor['email'], $otro['user']->fresh()->email);
        $this->assertStringContainsString('borrado+', (string) $doctor['user']->fresh()->email);
        $this->assertSame($doctor['email'], $doctor['user']->fresh()->email_original);
    }

    /* ------------------------------------------------------------------ */
    /* La pantalla                                                        */
    /* ------------------------------------------------------------------ */

    public function test_la_lista_oculta_las_archivadas_y_las_muestra_en_su_vista(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $doctor = $this->doctor('Listado');
        $nombre = $doctor['medico']->nombre_mostrar;

        $panel = Livewire::test(Cuentas::class)->set('search', $doctor['medico']->reg_medico);
        $panel->assertSee($nombre)->assertSee('Borrar la cuenta');

        $this->cuentas->archivarMedico($doctor['medico'], 'prueba', $admin);

        // En «Vigentes» ya no está; en «Archivados» sí, con su correo liberado y el botón de restaurar.
        $panel->set('search', $doctor['medico']->reg_medico)->assertDontSee($nombre);
        $panel->set('estado', 'archivados')
            ->assertSee($nombre)
            ->assertSee('Archivado', false)
            ->assertSee('Restaurar')
            ->assertSee($doctor['email']) // el correo original, como dato de la ficha archivada
            ->assertDontSee('Borrar la cuenta');

        $panel->call('restaurarArchivo', $doctor['medico']->id)->assertHasNoErrors();
        $this->assertFalse($doctor['medico']->fresh()->estaArchivado());
    }

    public function test_la_ventana_pide_escribir_el_nombre_para_confirmar(): void
    {
        $this->actingAs($this->admin());
        $doctor = $this->doctor('Confirmacion');
        $nombre = $doctor['medico']->nombre_mostrar;

        $panel = Livewire::test(Cuentas::class)->set('search', $doctor['medico']->reg_medico)
            ->call('abrirArchivo', $doctor['medico']->id)
            ->assertSet('modal', 'archivar')
            ->set('confirmacionArchivo', 'otra cosa')
            ->call('confirmarArchivo')
            ->assertHasErrors(['confirmacionArchivo'])
            ->assertSet('modal', 'archivar');

        $this->assertFalse($doctor['medico']->fresh()->estaArchivado(), 'Sin la confirmación no se archiva.');

        $panel->set('confirmacionArchivo', $nombre)
            ->set('motivoArchivo', 'Se jubiló')
            ->call('confirmarArchivo')
            ->assertHasNoErrors()
            ->assertSet('modal', null)
            ->assertSee('Borraste la cuenta');

        $ficha = $doctor['medico']->fresh();
        $this->assertTrue($ficha->estaArchivado());
        $this->assertSame('Se jubiló', $ficha->archivado_motivo);
    }

    public function test_el_detalle_de_una_cuenta_archivada_no_se_muestra(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $doctor = $this->doctor('Detalle');

        $this->get(route('admin.cuentas.medico', $doctor['medico']))->assertOk();

        $this->cuentas->archivarMedico($doctor['medico'], '', $admin);

        $this->get(route('admin.cuentas.medico', $doctor['medico']))->assertNotFound();
    }

    public function test_el_detalle_tiene_la_zona_de_riesgo_y_tambien_borra_desde_ahi(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $doctor = $this->doctor('DesdeDetalle');

        $this->get(route('admin.cuentas.medico', $doctor['medico']))->assertOk()
            ->assertSee('Zona de riesgo')
            ->assertSee('Borrar la cuenta');

        Livewire::test(\App\Http\Livewire\Admin\DetalleDelMedico::class, ['medico' => $doctor['medico']])
            ->call('abrirArchivo', $doctor['medico']->id)
            ->assertSet('modal', 'archivar')
            ->set('confirmacionArchivo', $doctor['medico']->nombre_mostrar)
            ->call('confirmarArchivo')
            ->assertRedirect(route('admin.cuentas'));

        $this->assertTrue($doctor['medico']->fresh()->estaArchivado());
    }

    public function test_un_doctor_comun_no_entra_al_panel_donde_se_borra(): void
    {
        $doctor = $this->doctor('Colado');
        $this->actingAs($doctor['user']);

        // La ruta del panel es la que guarda la acción de borrar; un médico común no la abre.
        $this->get('/admin/cuentas')->assertForbidden();
    }

    public function test_la_cuenta_archivada_no_se_ofrece_para_vincular_ni_para_una_cita(): void
    {
        $actor = $this->admin();
        $doctor = $this->doctor('Vinculos');
        $this->cuentas->archivarMedico($doctor['medico'], '', $actor);

        $this->assertArrayNotHasKey(
            $doctor['medico']->reg_medico,
            $this->cuentas->registrosAsignables($this->doctor('Otro')['medico']),
        );

        $doctores = app(\App\Clinica\Agenda\MedicosDeLaCita::class)->doctores($doctor['medico']->reg_medico);
        $this->assertFalse($doctores->contains('id', $doctor['medico']->id));
    }
}
