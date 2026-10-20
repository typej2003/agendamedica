<?php

namespace Tests\Feature;

use App\Clinica\ContextoTrabajo;
use App\Http\Livewire\Admin\Cuentas;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **El alta del centro médico y su asociación de doctores desde el panel** (Fase 2 del cambio de
 * `reg_medico`, 2026-10-20).
 *
 * Lo que se prueba:
 *
 *  - un **centro** se crea como una fila de `medicos` con `es_centro`, su `reg_medico` y **sin cuenta de
 *    acceso** (`user_id` nulo, no aparece en las cuentas ni se le puede crear acceso);
 *  - el centro **asocia un doctor ya registrado** (la fila de `medico_registros` del doctor) y el doctor
 *    pasa a ver el centro **en su contexto**;
 *  - el **formato de los reportes** (`centro`/`medico`) se guarda desde la edición;
 *  - la **secretaría** creada para el centro entra con el centro como contexto, y su lista es la del centro.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class CentrosMedicosPanelTest extends TestCase
{
    use DatabaseTransactions;

    private CuentaService $cuentas;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->cuentas = app(CuentaService::class);
    }

    private function admin(): User
    {
        $user = $this->cuentas->crearAdministrador('Admin Centros', 'admin-centros-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    // ------------------------------------------------------------------------------ Alta del centro

    public function test_el_servicio_crea_un_centro_sin_cuenta_de_acceso(): void
    {
        $centro = $this->cuentas->crearCentro([
            'name'       => 'Centro',
            'lastname'   => 'Médico',
            'reg_medico' => 'centro-' . substr(uniqid(), -5),
        ]);

        $this->assertTrue((bool) $centro->es_centro);
        $this->assertNull($centro->user_id, 'Un centro no tiene cuenta de acceso.');
        $this->assertNull($centro->email);
        $this->assertSame(1, Medico::where('reg_medico', $centro->reg_medico)->count());
        // Y no se creó ninguna cuenta con ese nombre.
        $this->assertSame(0, User::where('name', 'Centro Médico')->count());

        // El `reg_medico` del centro es único.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->cuentas->crearCentro(['name' => 'Otro', 'lastname' => 'Centro', 'reg_medico' => $centro->reg_medico]);
    }

    public function test_un_centro_no_puede_tener_cuenta_de_acceso(): void
    {
        $centro = $this->cuentas->crearCentro([
            'name' => 'Centro', 'lastname' => 'Sin Login', 'reg_medico' => 'centro-' . substr(uniqid(), -5),
            'email' => 'centro-' . substr(uniqid(), -6) . '@example.com',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->cuentas->darAccesoAMedico($centro);
    }

    public function test_el_panel_crea_el_centro_con_el_check(): void
    {
        $this->actingAs($this->admin());

        $regMedico = 'centro-' . substr(uniqid(), -5);

        Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'medico')
            ->set('es_centro', true)
            ->set('name', 'Centro del Panel')
            ->set('lastname', 'Sede Norte')
            ->set('reg_medico', $regMedico)
            ->call('guardarAlta')
            ->assertHasNoErrors()
            ->assertSet('claveGenerada', null);

        $centro = Medico::where('reg_medico', $regMedico)->first();

        $this->assertNotNull($centro);
        $this->assertTrue((bool) $centro->es_centro);
        $this->assertNull($centro->user_id);
    }

    public function test_el_formato_de_reportes_se_guarda_desde_la_edicion(): void
    {
        $this->actingAs($this->admin());

        $centro = $this->cuentas->crearCentro([
            'name' => 'Centro', 'lastname' => 'Formatos', 'reg_medico' => 'centro-' . substr(uniqid(), -5),
        ]);

        Livewire::test(Cuentas::class)
            ->call('abrirEditar', 'medico', $centro->id)
            ->assertSet('es_centro', true)
            ->set('formato_reportes', 'centro')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertSame('centro', $centro->fresh()->formato_reportes);

        // Y el valor sólo puede ser uno de los dos del dominio.
        Livewire::test(Cuentas::class)
            ->call('abrirEditar', 'medico', $centro->id)
            ->set('formato_reportes', 'inventado')
            ->call('guardarEdicion')
            ->assertHasErrors(['formato_reportes']);
    }

    // ------------------------------------------------------------------ Asociar un doctor al centro

    public function test_el_centro_asocia_un_doctor_existente_y_el_doctor_lo_ve_en_su_contexto(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $this->actingAs($this->admin());

        $centro = $this->cuentas->crearCentro([
            'name' => 'Centro', 'lastname' => 'Asocia', 'reg_medico' => 'centro-' . substr(uniqid(), -5),
        ]);

        $alta = $this->cuentas->crearMedico([
            'name'       => 'Doctor',
            'lastname'   => 'Asociado',
            'email'      => 'doctor-asoc-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'dr-' . substr(uniqid(), -5),
        ]);
        $doctor = $alta['medico'];
        $doctor->specialties()->attach($gineco->id);

        // Antes de asociarlo, el doctor no ve el centro.
        $this->assertArrayNotHasKey($centro->reg_medico, app(ContextoTrabajo::class)->disponibles($alta['user']));

        Livewire::test(Cuentas::class)
            ->call('abrirRegistros', $centro->id)
            ->assertSee('Doctores del centro')
            ->set('doctorNuevo', (string) $doctor->id)
            ->call('vincularDoctorAlCentro')
            ->assertHasNoErrors()
            ->assertSee('Doctor Asociado');

        $this->assertTrue(
            MedicoRegistro::where('medico_id', $doctor->id)->where('reg_medico', $centro->reg_medico)->exists(),
            'La asociación es la fila de `medico_registros` del doctor con el reg_medico del centro.',
        );

        // Y ahora el doctor ve el centro en su contexto, con **él** como médico.
        $accesos = app(ContextoTrabajo::class)->disponibles($alta['user']);
        $this->assertArrayHasKey($centro->reg_medico, $accesos);
        $this->assertSame($doctor->id, $accesos[$centro->reg_medico]['medico']->id);

        // Desvincularlo lo saca y no borra nada del doctor.
        Livewire::test(Cuentas::class)
            ->call('abrirRegistros', $centro->id)
            ->call('quitarDoctorDelCentro', $doctor->id)
            ->assertHasNoErrors();

        $this->assertFalse(
            MedicoRegistro::where('medico_id', $doctor->id)->where('reg_medico', $centro->reg_medico)->exists(),
        );
        $this->assertNotNull(Medico::find($doctor->id), 'Desvincular no borra al doctor.');
    }

    // ------------------------------------------------------------------------ La secretaría del centro

    public function test_la_secretaria_del_centro_trabaja_con_el_centro_como_contexto(): void
    {
        $centro = $this->cuentas->crearCentro([
            'name' => 'Centro', 'lastname' => 'Con Secretaria', 'reg_medico' => 'centro-' . substr(uniqid(), -5),
        ]);

        $secretaria = $this->cuentas->crearSecretaria(
            'Secretaria del Centro',
            'sec-centro-' . substr(uniqid(), -6) . '@example.com',
            $centro->reg_medico,
        )['user'];
        $secretaria->forceFill(['must_change_password' => false])->save();

        $accesos = app(ContextoTrabajo::class)->disponibles($secretaria);

        $this->assertArrayHasKey($centro->reg_medico, $accesos);
        $this->assertSame($centro->id, $accesos[$centro->reg_medico]['medico']->id);
        $this->assertTrue($accesos[$centro->reg_medico]['es_centro']);

        // Y la lista de pacientes es la del centro: sin consultorios ni especialidades, la pantalla abre igual.
        $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $centro->reg_medico,
            'specialty_id' => null,
            'office_id'    => null,
        ]]);

        $this->actingAs($secretaria)->get('/clinica/pacientes')->assertOk();
    }

    // ---------------------------------------------------------------------------------- Un caso real

    public function test_un_doctor_nuevo_se_crea_con_su_registro_y_se_le_asigna_el_centro(): void
    {
        $centro = $this->cuentas->crearCentro([
            'name' => 'Centro', 'lastname' => 'Con Doctores', 'reg_medico' => 'centro-' . substr(uniqid(), -5),
        ]);

        $alta = $this->cuentas->crearMedico([
            'name'       => 'Doctor',
            'lastname'   => 'Nuevo',
            'email'      => 'doctor-nuevo-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'dr-' . substr(uniqid(), -5),
        ]);

        // El doctor nuevo arranca con SU registro profesional, no con el del centro.
        $this->assertSame([$alta['medico']->reg_medico], $this->cuentas->registrosDe($alta['medico']));

        // Y el centro lo asocia: ahora tiene los dos.
        $this->cuentas->asignarRegistro($alta['medico'], $centro->reg_medico);

        $this->assertSame(
            [$alta['medico']->reg_medico, $centro->reg_medico],
            $this->cuentas->registrosDe($alta['medico']),
        );

        // El centro se ofrece marcado en la lista de registros asignables de otro doctor.
        $otro = $this->cuentas->crearMedico([
            'name' => 'Otro', 'lastname' => 'Doctor',
            'email' => 'otro-' . substr(uniqid(), -6) . '@example.com', 'reg_medico' => 'dr3-' . substr(uniqid(), -5),
        ])['medico'];

        $asignables = $this->cuentas->registrosAsignables($otro);
        $this->assertArrayHasKey($centro->reg_medico, $asignables);
        $this->assertStringContainsString('centro médico', $asignables[$centro->reg_medico]);
    }

    /** El centro no aparece en la pestaña de cuentas (no tiene `user_id`), pero sí en la de médicos. */
    public function test_el_centro_se_ve_en_la_lista_de_fichas_y_no_entre_las_cuentas(): void
    {
        $this->actingAs($this->admin());

        $regMedico = 'centro-' . substr(uniqid(), -5);
        Livewire::test(Cuentas::class)
            ->call('abrirAlta', 'medico')
            ->set('es_centro', true)
            ->set('name', 'Centro Visible')
            ->set('lastname', 'Listado')
            ->set('reg_medico', $regMedico)
            ->call('guardarAlta')
            ->assertHasNoErrors()
            ->set('search', 'Centro Visible')
            ->assertSee('Centro Visible')
            ->assertSee('Centro médico');

        // No es una cuenta: no está en `users`.
        $this->assertNull(User::where('name', 'like', '%Centro Visible%')->first());
    }
}
