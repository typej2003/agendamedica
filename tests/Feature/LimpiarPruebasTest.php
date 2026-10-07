<?php

namespace Tests\Feature;

use App\Models\MedicoPaciente;
use App\Models\Paciente;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `php artisan produccion:limpiar-pruebas`: borra las cuentas y los datos de prueba para la primera carga real.
 *
 * Lo que fijan: no escribe sin `--confirmar`, se niega a dejar al sistema sin administrador real, borra
 * solo lo de las cuentas de prueba (y los pacientes que ningún otro médico tiene) y respeta lo que se pide
 * conservar. Los seeders del entorno de pruebas ya traen cuentas de prueba (carlos@gmail.com…): también
 * entran en el borrado, dentro de la transacción del test, que se revierte sola.
 */
class LimpiarPruebasTest extends TestCase
{
    use DatabaseTransactions;

    private CuentaService $cuentas;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->cuentas = app(CuentaService::class);
    }

    /** Médico con un paciente propio y una fila con su `reg_medico`. */
    private function medicoConDatos(string $email, string $reg): array
    {
        $r = $this->cuentas->crearMedico(['name' => 'Doc', 'lastname' => 'Test', 'email' => $email, 'reg_medico' => $reg]);
        $paciente = Paciente::create(['nombres' => 'Paciente', 'apellidos' => $reg]);
        MedicoPaciente::create(['medico_id' => $r['medico']->id, 'paciente_id' => $paciente->id, 'reg_medico' => $reg]);
        DB::table('sync_changes')->insert([
            'reg_medico' => $reg, 'table_name' => 'cola', 'record_id' => 1, 'operation' => 'created', 'occurred_at' => now(),
        ]);

        return $r + ['paciente' => $paciente];
    }

    private function administradorReal(): User
    {
        return $this->cuentas->crearAdministrador('Admin Real', 'admin-real-' . uniqid() . '@ddr.com')['user'];
    }

    public function test_sin_confirmar_solo_simula_y_no_borra_nada(): void
    {
        $this->administradorReal();
        $prueba = $this->medicoConDatos('carga-prueba-98-' . uniqid() . '@example.com', 'gineco-prueba-98');

        $this->artisan('produccion:limpiar-pruebas')->assertExitCode(0);

        $this->assertNotNull(User::find($prueba['user']->id));
        $this->assertNotNull(Paciente::find($prueba['paciente']->id));
        $this->assertSame(1, DB::table('sync_changes')->where('reg_medico', 'gineco-prueba-98')->count());
    }

    public function test_se_niega_si_no_quedaria_ningun_administrador_real(): void
    {
        // En el entorno de pruebas los administradores sembrados (root@admin.com, admin@gmail.com) son de prueba.
        User::administradores()->whereNotIn('email', ['root@admin.com', 'admin@gmail.com'])->get()
            ->each(fn (User $u) => $u->syncRoles([]));
        $prueba = $this->medicoConDatos('carga-prueba-97-' . uniqid() . '@example.com', 'gineco-prueba-97');

        $this->artisan('produccion:limpiar-pruebas', ['--confirmar' => true])
            ->assertExitCode(1);

        $this->assertNotNull(User::find($prueba['user']->id));
    }

    public function test_confirmado_borra_lo_de_prueba_y_deja_lo_real(): void
    {
        $admin = $this->administradorReal();
        $real = $this->medicoConDatos('doctora-real-' . uniqid() . '@clinica.com', 'real-' . uniqid());
        $prueba = $this->medicoConDatos('carga-prueba-96-' . uniqid() . '@example.com', 'gineco-prueba-96');

        $this->artisan('produccion:limpiar-pruebas', ['--confirmar' => true])
            ->expectsConfirmation('Esto borra esas cuentas y sus datos de forma definitiva. ¿Continuar?', 'yes')
            ->assertExitCode(0);

        // Lo de prueba: cuenta, ficha, paciente, filas por reg_medico y vínculos.
        $this->assertNull(User::find($prueba['user']->id));
        $this->assertNull(DB::table('medicos')->find($prueba['medico']->id));
        $this->assertNull(Paciente::find($prueba['paciente']->id));
        $this->assertSame(0, DB::table('sync_changes')->where('reg_medico', 'gineco-prueba-96')->count());
        $this->assertSame(0, DB::table('medico_pacientes')->where('medico_id', $prueba['medico']->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $prueba['user']->id)->where('model_type', User::class)->count());

        // Lo real, intacto.
        $this->assertNotNull(User::find($admin->id));
        $this->assertNotNull(User::find($real['user']->id));
        $this->assertNotNull(Paciente::find($real['paciente']->id));
        $this->assertSame(1, DB::table('sync_changes')->where('reg_medico', $real['medico']->reg_medico)->count());
        $this->assertSame(1, DB::table('medico_pacientes')->where('medico_id', $real['medico']->id)->count());
        // Los planes (catálogo) no se tocan.
        $this->assertGreaterThan(0, DB::table('planes')->count());
    }

    public function test_un_paciente_compartido_con_un_medico_real_no_se_borra(): void
    {
        $this->administradorReal();
        $real = $this->medicoConDatos('doctora-real-' . uniqid() . '@clinica.com', 'real-' . uniqid());
        $prueba = $this->medicoConDatos('carga-prueba-95-' . uniqid() . '@example.com', 'gineco-prueba-95');
        MedicoPaciente::create(['medico_id' => $real['medico']->id, 'paciente_id' => $prueba['paciente']->id, 'reg_medico' => $real['medico']->reg_medico]);

        $this->artisan('produccion:limpiar-pruebas', ['--confirmar' => true])
            ->expectsConfirmation('Esto borra esas cuentas y sus datos de forma definitiva. ¿Continuar?', 'yes')
            ->assertExitCode(0);

        $this->assertNotNull(Paciente::find($prueba['paciente']->id));
    }

    public function test_conservar_deja_la_cuenta_pedida(): void
    {
        $this->administradorReal();
        $prueba = $this->medicoConDatos('carga-prueba-94-' . uniqid() . '@example.com', 'gineco-prueba-94');

        $this->artisan('produccion:limpiar-pruebas', ['--confirmar' => true, '--conservar' => [$prueba['user']->email]])
            ->expectsConfirmation('Esto borra esas cuentas y sus datos de forma definitiva. ¿Continuar?', 'yes')
            ->assertExitCode(0);

        $this->assertNotNull(User::find($prueba['user']->id));
        $this->assertSame(1, DB::table('sync_changes')->where('reg_medico', 'gineco-prueba-94')->count());
    }
}
