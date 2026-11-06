<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Cuentas;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use App\Services\CupoDelPlan;
use App\Services\CuentaService;
use App\Services\ServicioService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Registros de datos (`reg_medico`) de un médico: un administrador le asigna o le quita acceso a los datos de otro
 * registro desde "Usuarios" → menú de 3 puntos → "Registros de datos".
 *
 * Lo que fijan: asignar **sólo** suma la fila de `medico_registros` —el vínculo paciente ↔ consultorio NO se
 * copia, porque es del tenant (Fase 1 del cambio de `reg_medico`)— y el médico igual ve los pacientes del
 * registro por el `reg_medico`; quitar deshace sólo el acceso y no borra ningún dato; el registro propio no se
 * quita; y el sync del médico que lo recibió devuelve de verdad los pacientes del otro registro.
 */
class RegistrosDeMedicoTest extends TestCase
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
        $user = $this->cuentas->crearAdministrador('Admin Panel', 'admin-reg-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /** Médico con cuenta lista para entrar y, si se pide, con un paciente propio. */
    private function medico(string $prefijo, bool $conPaciente = false): array
    {
        $sufijo = uniqid();
        $r = $this->cuentas->crearMedico([
            'name' => $prefijo, 'lastname' => 'Test', 'email' => "{$prefijo}-{$sufijo}@example.com", 'reg_medico' => "test-{$prefijo}-{$sufijo}",
        ], 'Clave12345');
        $r['user']->forceFill(['must_change_password' => false])->save();

        // Esta prueba es de registros, no de cupos: el consultorio "real" tiene plan para 3 médicos.
        app(ServicioService::class)->renovar($r['medico']->reg_medico, Plan::porCodigo('equipo-3'));

        if ($conPaciente) {
            $r['paciente'] = Paciente::create(['nombres' => 'Paciente', 'apellidos' => $prefijo]);
            MedicoPaciente::create(['medico_id' => $r['medico']->id, 'paciente_id' => $r['paciente']->id, 'numhistoria' => '1', 'reg_medico' => $r['medico']->reg_medico]);
        }

        return $r;
    }

    public function test_asignar_suma_el_registro_y_no_copia_los_pacientes(): void
    {
        $real = $this->medico('real', true);
        $temporal = $this->medico('temporal');

        $this->cuentas->asignarRegistro($temporal['medico'], $real['medico']->reg_medico);

        $this->assertSame([$temporal['medico']->reg_medico, $real['medico']->reg_medico], $this->cuentas->registrosDe($temporal['medico']));

        // **El vínculo no se copia**: es del consultorio (`(reg_medico, paciente_id)`), no del médico. El
        // paciente sigue teniendo una sola fila, con el `medico_id` del médico que lo vinculó.
        $this->assertSame(0, MedicoPaciente::where('medico_id', $temporal['medico']->id)->count());
        $this->assertSame(1, MedicoPaciente::where('reg_medico', $real['medico']->reg_medico)->count());
        $this->assertSame($real['medico']->id, (int) MedicoPaciente::where('reg_medico', $real['medico']->reg_medico)
            ->where('paciente_id', $real['paciente']->id)->value('medico_id'));
    }

    public function test_quitar_deshace_solo_el_acceso_y_no_borra_datos(): void
    {
        $real = $this->medico('real', true);
        $temporal = $this->medico('temporal', true);
        $this->cuentas->asignarRegistro($temporal['medico'], $real['medico']->reg_medico);

        $this->cuentas->quitarRegistro($temporal['medico'], $real['medico']->reg_medico);

        $this->assertSame([$temporal['medico']->reg_medico], $this->cuentas->registrosDe($temporal['medico']));
        // **No se borran filas del pivote**: el vínculo es del consultorio, no del médico que tenía el acceso.
        $this->assertTrue(MedicoPaciente::where('reg_medico', $real['medico']->reg_medico)
            ->where('paciente_id', $real['paciente']->id)->exists());
        // Su paciente propio y todo lo del médico real siguen ahí.
        $this->assertTrue(MedicoPaciente::where('medico_id', $temporal['medico']->id)->where('paciente_id', $temporal['paciente']->id)->exists());
        $this->assertNotNull(Paciente::find($real['paciente']->id));
        $this->assertSame(1, MedicoPaciente::where('medico_id', $real['medico']->id)->count());
    }

    public function test_reglas_el_propio_no_se_quita_el_inexistente_o_repetido_se_rechaza(): void
    {
        $real = $this->medico('real');
        $otro = $this->medico('otro');

        foreach ([
            fn () => $this->cuentas->quitarRegistro($otro['medico'], $otro['medico']->reg_medico),   // el propio
            fn () => $this->cuentas->quitarRegistro($otro['medico'], $real['medico']->reg_medico),   // no lo tiene
            fn () => $this->cuentas->asignarRegistro($otro['medico'], 'no-existe-' . substr(uniqid(), -6)),      // inventado
            fn () => $this->cuentas->asignarRegistro($otro['medico'], $otro['medico']->reg_medico),  // el propio otra vez
            fn () => $this->cuentas->asignarRegistro($otro['medico'], ''),
        ] as $intento) {
            try {
                $intento();
                $this->fail('Debía rechazarse.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('regNuevo', $e->errors());
            }
        }

        $this->cuentas->asignarRegistro($otro['medico'], $real['medico']->reg_medico);
        $this->expectException(ValidationException::class);
        $this->cuentas->asignarRegistro($otro['medico'], $real['medico']->reg_medico);   // ya lo tiene
    }

    public function test_los_asignables_son_los_de_otros_medicos_menos_los_que_ya_tiene(): void
    {
        $real = $this->medico('real');
        $otro = $this->medico('otro');

        $antes = $this->cuentas->registrosAsignables($otro['medico']);
        $this->assertArrayHasKey($real['medico']->reg_medico, $antes);
        $this->assertArrayNotHasKey($otro['medico']->reg_medico, $antes);

        $this->cuentas->asignarRegistro($otro['medico'], $real['medico']->reg_medico);
        $this->assertArrayNotHasKey($real['medico']->reg_medico, $this->cuentas->registrosAsignables($otro['medico']));
    }

    public function test_el_sync_del_medico_que_lo_recibio_trae_los_pacientes_del_otro_registro(): void
    {
        $real = $this->medico('real', true);
        $temporal = $this->medico('temporal');
        $this->cuentas->asignarRegistro($temporal['medico'], $real['medico']->reg_medico);

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/app/login', ['email' => $temporal['user']->email, 'password' => 'Clave12345'])->assertOk();
        $this->app['auth']->forgetGuards();
        $sync = $this->withHeader('Authorization', 'Bearer ' . $login->json('access_token'))
            ->postJson('/api/app/sync-app-data', ['since' => null, 'cambios' => []])->assertOk();

        $this->assertContains($real['paciente']->id, collect($sync->json('pacientes'))->pluck('id')->all());
    }

    /* ------------------------------------------------------------------ */
    /* Pantalla                                                            */
    /* ------------------------------------------------------------------ */

    public function test_el_panel_asigna_y_quita(): void
    {
        $real = $this->medico('real', true);
        $temporal = $this->medico('temporal');
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('abrirRegistros', $temporal['medico']->id)
            ->assertSet('modal', 'registros')
            ->assertSee($real['medico']->reg_medico)            // aparece en el selector de asignables
            ->set('regNuevo', $real['medico']->reg_medico)
            ->call('asignarRegistro')
            ->assertHasNoErrors()
            ->assertSet('avisoRegistros', "Registro {$real['medico']->reg_medico} asignado: el médico ve sus pacientes y su agenda.")
            ->call('quitarRegistro', $real['medico']->reg_medico)
            ->assertHasNoErrors()
            ->call('quitarRegistro', $temporal['medico']->reg_medico)    // el propio: error visible, no excepción
            ->assertHasErrors(['regNuevo']);

        $this->assertSame(0, MedicoRegistro::where('medico_id', $temporal['medico']->id)->where('reg_medico', $real['medico']->reg_medico)->count());
    }

    public function test_agregar_un_consultorio_crea_la_ficha_hija_sin_cuenta_ni_plan(): void
    {
        $real = $this->medico('hijo-alta');

        $ficha = $this->cuentas->agregarConsultorio($real['medico'], 'hijo-alta-2');

        $this->assertSame('hijo-alta-2', $ficha->reg_medico);
        $this->assertTrue($ficha->esConsultorioHijo());
        $this->assertSame($real['medico']->id, (int) $ficha->medico_padre_id);
        $this->assertNull($ficha->user_id, 'No crea cuenta.');
        $this->assertTrue((bool) $ficha->es_centro, 'No cuenta como médico del plan.');
        $this->assertSame(Medico::FORMATO_DEL_MEDICO, $ficha->formato_reportes);
        $this->assertSame(0, RegMedicoServicio::where('reg_medico', 'hijo-alta-2')->count(), 'Sin plan propio: usa el del padre.');
        $this->assertContains('hijo-alta-2', $real['medico']->fresh()->registrosDeTrabajo());

        // El plan del padre sigue cubriendo a un solo médico: el mismo doctor no suma un asiento.
        $this->assertSame(1, app(CupoDelPlan::class)->conteo($real['medico']->reg_medico)['medicos']);
        $this->assertSame(1, app(CupoDelPlan::class)->conteo('hijo-alta-2')['medicos']);
    }

    public function test_no_se_agrega_un_consultorio_repetido_del_propio_ni_desde_un_centro_o_un_hijo(): void
    {
        $real = $this->medico('hijo-err');
        $otro = $this->medico('hijo-err-otro');
        $hijo = $this->cuentas->agregarConsultorio($real['medico'], 'hijo-err-2');
        $centro = $this->cuentas->crearCentro(['name' => 'Centro', 'lastname' => 'Err', 'reg_medico' => 'hijo-err-centro']);

        foreach ([
            fn () => $this->cuentas->agregarConsultorio($real['medico'], 'hijo-err-2'),                  // ya existe
            fn () => $this->cuentas->agregarConsultorio($real['medico'], $otro['medico']->reg_medico),   // de otro médico
            fn () => $this->cuentas->agregarConsultorio($real['medico'], $real['medico']->reg_medico),   // el principal
            fn () => $this->cuentas->agregarConsultorio($real['medico'], ''),
            fn () => $this->cuentas->agregarConsultorio($real['medico'], str_repeat('x', 21)),
            fn () => $this->cuentas->agregarConsultorio($hijo, 'hijo-nieto'),                            // un hijo no tiene hijos
            fn () => $this->cuentas->agregarConsultorio($centro, 'hijo-centro-2'),                       // un centro no tiene consultorios propios
        ] as $intento) {
            try {
                $intento();
                $this->fail('Debía rechazarse.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('reg_consultorio', $e->errors());
            }
        }
    }

    public function test_la_ventana_de_registros_agrega_el_consultorio(): void
    {
        $real = $this->medico('hijo-ui');
        $this->actingAs($this->admin());

        Livewire::test(Cuentas::class)
            ->call('abrirRegistros', $real['medico']->id)
            ->assertSee('Agregar un consultorio')
            ->set('regConsultorio', 'hijo-ui-2')
            ->call('agregarConsultorio')
            ->assertHasNoErrors()
            ->assertSee('hijo-ui-2')
            ->set('regConsultorio', 'hijo-ui-2')
            ->call('agregarConsultorio')
            ->assertHasErrors(['reg_consultorio']);

        $this->assertSame(1, Medico::where('reg_medico', 'hijo-ui-2')->count());
        $this->assertTrue(MedicoRegistro::where('medico_id', $real['medico']->id)->where('reg_medico', 'hijo-ui-2')->exists());
    }

    public function test_la_accion_se_comprueba_en_cada_peticion(): void
    {
        $real = $this->medico('real', true);
        $temporal = $this->medico('temporal');
        $admin = $this->admin();
        $this->actingAs($admin);

        $panel = Livewire::test(Cuentas::class)
            ->call('abrirRegistros', $temporal['medico']->id)
            ->set('regNuevo', $real['medico']->reg_medico);

        // Le quitan el rol a mitad de sesión: la siguiente acción ya no puede surtir efecto.
        $admin->removeRole('Administrador');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin->fresh());

        $panel->call('asignarRegistro');

        $this->assertSame([$temporal['medico']->reg_medico], $this->cuentas->registrosDe($temporal['medico']));
    }
}
