<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Cuentas;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Registros de datos (`reg_medico`) de un médico: un administrador le asigna o le quita acceso a los datos de otro
 * registro desde "Usuarios" → menú de 3 puntos → "Registros de datos".
 *
 * Lo que fijan: asignar suma la fila de `medico_registros` Y vincula los pacientes (el sync lee por las dos);
 * quitar deshace solo eso y no borra ningún dato; el registro propio no se quita; y el sync del médico que lo
 * recibió devuelve de verdad los pacientes del otro registro.
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
        $user = $this->cuentas->crearAdministrador('Admin Panel', 'admin-reg-' . uniqid() . '@example.com')['user'];
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

        if ($conPaciente) {
            $r['paciente'] = Paciente::create(['nombres' => 'Paciente', 'apellidos' => $prefijo]);
            MedicoPaciente::create(['medico_id' => $r['medico']->id, 'paciente_id' => $r['paciente']->id, 'numhistoria' => '1', 'reg_medico' => $r['medico']->reg_medico]);
        }

        return $r;
    }

    public function test_asignar_suma_el_registro_y_vincula_los_pacientes(): void
    {
        $real = $this->medico('real', true);
        $temporal = $this->medico('temporal');

        $vinculados = $this->cuentas->asignarRegistro($temporal['medico'], $real['medico']->reg_medico);

        $this->assertSame(1, $vinculados);
        $this->assertSame([$temporal['medico']->reg_medico, $real['medico']->reg_medico], $this->cuentas->registrosDe($temporal['medico']));
        $this->assertTrue(
            MedicoPaciente::where('medico_id', $temporal['medico']->id)->where('paciente_id', $real['paciente']->id)
                ->where('reg_medico', $real['medico']->reg_medico)->exists()
        );
        // Lo del médico real queda intacto.
        $this->assertSame(1, MedicoPaciente::where('medico_id', $real['medico']->id)->count());
    }

    public function test_quitar_deshace_solo_el_acceso_y_no_borra_datos(): void
    {
        $real = $this->medico('real', true);
        $temporal = $this->medico('temporal', true);
        $this->cuentas->asignarRegistro($temporal['medico'], $real['medico']->reg_medico);

        $this->cuentas->quitarRegistro($temporal['medico'], $real['medico']->reg_medico);

        $this->assertSame([$temporal['medico']->reg_medico], $this->cuentas->registrosDe($temporal['medico']));
        $this->assertFalse(MedicoPaciente::where('medico_id', $temporal['medico']->id)->where('paciente_id', $real['paciente']->id)->exists());
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
            fn () => $this->cuentas->asignarRegistro($otro['medico'], 'no-existe-' . uniqid()),      // inventado
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
            ->assertSet('avisoRegistros', "Registro {$real['medico']->reg_medico} asignado: 1 pacientes vinculados.")
            ->call('quitarRegistro', $real['medico']->reg_medico)
            ->assertHasNoErrors()
            ->call('quitarRegistro', $temporal['medico']->reg_medico)    // el propio: error visible, no excepción
            ->assertHasErrors(['regNuevo']);

        $this->assertSame(0, MedicoRegistro::where('medico_id', $temporal['medico']->id)->where('reg_medico', $real['medico']->reg_medico)->count());
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
