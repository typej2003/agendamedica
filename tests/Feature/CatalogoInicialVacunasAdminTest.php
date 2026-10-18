<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\CatalogoInicialVacunas;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El ABM del catálogo inicial de vacunas (WEB-12.8, `/admin/catalogo-inicial/vacunas`): sólo Root y Administrador; valida; y **opera
 * únicamente sobre las filas sin dueño** (`reg_medico` NULL), nunca sobre las de un consultorio.
 */
class CatalogoInicialVacunasAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas_dosis')->whereNull('reg_medico')->delete();
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Vac', 'admin-vac-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function conRol(string $rol): User
    {
        $user = User::create([
            'name' => $rol, 'email' => strtolower($rol) . '-vac-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function inicial(string $codigo = 'BCG', array $extra = []): int
    {
        return DB::table('vacunas')->insertGetId(array_merge([
            'reg_medico' => null, 'codi_vacuna' => $codigo, 'vacuna' => 'Vacuna ' . $codigo, 'fija' => 'O', 'edad_inicial_meses' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function dosis(string $codigo, int $numero, ?string $reg = null, string $rotulo = 'Dosis'): int
    {
        return DB::table('vacunas_dosis')->insertGetId([
            'reg_medico' => $reg, 'codi_vacuna' => $codigo, 'dosis' => $numero, 'dosis_descri' => $rotulo, 'meses' => $numero,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------ acceso */

    public function test_solo_root_y_administrador_entran(): void
    {
        $this->get('/admin/catalogo-inicial/vacunas')->assertRedirect('/login');

        foreach (['Medico', 'Secretaria'] as $rol) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->conRol($rol))->get('/admin/catalogo-inicial/vacunas')->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin());
        $this->get('/admin/catalogo-inicial/vacunas')->assertOk()->assertSee('Catálogo inicial · Vacunas');
        Livewire::test('layouts.aside')->assertSee(route('admin.catalogo-inicial.vacunas'));
    }

    public function test_un_medico_no_puede_invocar_las_acciones_del_componente(): void
    {
        $medico = $this->conRol('Medico');

        $this->actingAs($medico);
        Livewire::test(CatalogoInicialVacunas::class)->assertForbidden();
    }

    /* ------------------------------------------------------------ vacunas */

    public function test_lista_solo_las_vacunas_iniciales_con_sus_dosis(): void
    {
        $this->inicial('BCG');
        $this->dosis('BCG', 1, null, 'Dosis única');
        DB::table('vacunas')->insert(['reg_medico' => 'med-a', 'codi_vacuna' => 'MEDA', 'vacuna' => 'Vacuna del médico A', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin());
        Livewire::test(CatalogoInicialVacunas::class)
            ->assertSee('Vacuna BCG')->assertSee('Dosis única')->assertSee('Obligatoria')
            ->assertDontSee('Vacuna del médico A');
    }

    public function test_alta_de_una_vacuna_inicial(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CatalogoInicialVacunas::class)
            ->call('abrirVacuna')
            ->set('codi_vacuna', 'rota')->set('vacuna', 'Rotavirus')->set('edad_inicial_meses', '2')->set('fija', 'R')->set('sexo', 'A')->set('cobertura', 'Diarrea por rotavirus')
            ->call('guardarVacuna')
            ->assertHasNoErrors()->assertSet('modal', null);

        $fila = DB::table('vacunas')->where('codi_vacuna', 'ROTA')->first();
        $this->assertNotNull($fila);
        $this->assertNull($fila->reg_medico);
        $this->assertSame('Rotavirus', $fila->vacuna);
        $this->assertSame(2, (int) $fila->edad_inicial_meses);
        $this->assertSame('R', $fila->fija);
    }

    public function test_validaciones_de_la_vacuna(): void
    {
        $this->inicial('BCG');
        $this->actingAs($this->admin());

        Livewire::test(CatalogoInicialVacunas::class)->call('abrirVacuna')
            ->call('guardarVacuna')->assertHasErrors(['codi_vacuna', 'vacuna']);

        Livewire::test(CatalogoInicialVacunas::class)->call('abrirVacuna')
            ->set('codi_vacuna', 'ABCDE')->set('vacuna', str_repeat('x', 101))->set('edad_inicial_meses', '-1')->set('fija', 'X')->set('sexo', 'Q')
            ->call('guardarVacuna')->assertHasErrors(['codi_vacuna', 'vacuna', 'edad_inicial_meses', 'fija', 'sexo']);

        // El código es único entre las iniciales (sin distinguir mayúsculas).
        Livewire::test(CatalogoInicialVacunas::class)->call('abrirVacuna')
            ->set('codi_vacuna', 'bcg')->set('vacuna', 'Otra BCG')->set('fija', 'O')
            ->call('guardarVacuna')->assertHasErrors('codi_vacuna');

        $this->assertSame(1, DB::table('vacunas')->whereNull('reg_medico')->count());
    }

    public function test_editar_cambia_el_codigo_y_arrastra_solo_las_dosis_iniciales(): void
    {
        $id = $this->inicial('BCG');
        $this->dosis('BCG', 1);
        $propia = $this->dosis('BCG', 1, 'med-a');

        $this->actingAs($this->admin());
        Livewire::test(CatalogoInicialVacunas::class)
            ->call('abrirVacuna', $id)->assertSet('codi_vacuna', 'BCG')
            ->set('codi_vacuna', 'BCG2')->set('vacuna', 'BCG nueva')
            ->call('guardarVacuna')->assertHasNoErrors();

        $this->assertSame('BCG nueva', DB::table('vacunas')->where('id', $id)->value('vacuna'));
        $this->assertSame(1, DB::table('vacunas_dosis')->whereNull('reg_medico')->where('codi_vacuna', 'BCG2')->count());
        // La dosis del médico no se movió.
        $this->assertSame('BCG', DB::table('vacunas_dosis')->where('id', $propia)->value('codi_vacuna'));
    }

    public function test_no_borra_una_vacuna_con_dosis_sin_confirmar(): void
    {
        $id = $this->inicial('BCG');
        $this->dosis('BCG', 1);
        $this->dosis('BCG', 2);
        $this->actingAs($this->admin());

        $panel = Livewire::test(CatalogoInicialVacunas::class)->call('abrirBorrar', $id)->call('borrarVacuna')->assertHasErrors('confirmaBorrado');
        $this->assertSame(1, DB::table('vacunas')->whereNull('reg_medico')->count());
        $this->assertSame(2, DB::table('vacunas_dosis')->whereNull('reg_medico')->count());

        $panel->set('confirmaBorrado', true)->call('borrarVacuna')->assertHasNoErrors();
        $this->assertSame(0, DB::table('vacunas')->whereNull('reg_medico')->count());
        $this->assertSame(0, DB::table('vacunas_dosis')->whereNull('reg_medico')->count());
    }

    public function test_borra_sin_confirmar_una_vacuna_sin_dosis_y_no_toca_lo_de_los_medicos(): void
    {
        $id = $this->inicial('BCG');
        DB::table('vacunas')->insert(['reg_medico' => 'med-a', 'codi_vacuna' => 'BCG', 'vacuna' => 'BCG de A', 'created_at' => now(), 'updated_at' => now()]);
        $propia = $this->dosis('BCG', 1, 'med-a');

        $this->actingAs($this->admin());
        Livewire::test(CatalogoInicialVacunas::class)->call('abrirBorrar', $id)->call('borrarVacuna')->assertHasNoErrors();

        $this->assertSame(0, DB::table('vacunas')->whereNull('reg_medico')->count());
        $this->assertSame(1, DB::table('vacunas')->where('reg_medico', 'med-a')->count());
        $this->assertSame(1, DB::table('vacunas_dosis')->where('id', $propia)->count());
    }

    public function test_una_vacuna_con_dueño_no_se_puede_abrir_ni_borrar_desde_el_admin(): void
    {
        $ajena = DB::table('vacunas')->insertGetId(['reg_medico' => 'med-a', 'codi_vacuna' => 'MEDA', 'vacuna' => 'De A', 'created_at' => now(), 'updated_at' => now()]);
        $dosisAjena = $this->dosis('MEDA', 1, 'med-a');
        $this->actingAs($this->admin());

        foreach ([['abrirVacuna', $ajena], ['abrirBorrar', $ajena], ['abrirDosis', $ajena], ['editarDosis', $dosisAjena], ['quitarDosis', $dosisAjena]] as [$accion, $id]) {
            try {
                Livewire::test(CatalogoInicialVacunas::class)->call($accion, $id);
                $this->fail("$accion debería fallar con una fila ajena");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('De A', DB::table('vacunas')->where('id', $ajena)->value('vacuna'));
        $this->assertSame(1, DB::table('vacunas_dosis')->where('id', $dosisAjena)->count());
    }

    /* ------------------------------------------------------------ dosis */

    public function test_alta_edicion_y_baja_de_una_dosis(): void
    {
        $id = $this->inicial('POLI');
        $this->actingAs($this->admin());

        $panel = Livewire::test(CatalogoInicialVacunas::class)
            ->call('abrirDosis', $id)
            ->set('dosis_numero', '1')->set('dosis_descri', '1era. Dosis')->set('meses', '2')->call('guardarDosis')->assertHasNoErrors()
            ->set('dosis_numero', '3')->set('dosis_descri', 'Refuerzo')->set('meses', '15,5')->call('guardarDosis');

        // «15,5» (coma) no es un número para PHP: se rechaza en vez de guardar 15.
        $panel->assertHasErrors('meses');
        $panel->set('meses', '15.5')->call('guardarDosis')->assertHasNoErrors();

        $filas = DB::table('vacunas_dosis')->whereNull('reg_medico')->where('codi_vacuna', 'POLI')->orderBy('dosis')->get();
        $this->assertCount(2, $filas);
        $this->assertSame('Refuerzo', $filas[1]->dosis_descri);
        $this->assertEquals(15.5, $filas[1]->meses);

        $panel->call('editarDosis', $filas[0]->id)->set('dosis_descri', 'Primera')->call('guardarDosis')->assertHasNoErrors();
        $this->assertSame('Primera', DB::table('vacunas_dosis')->where('id', $filas[0]->id)->value('dosis_descri'));

        $panel->call('quitarDosis', $filas[1]->id);
        $this->assertSame(1, DB::table('vacunas_dosis')->whereNull('reg_medico')->where('codi_vacuna', 'POLI')->count());
    }

    public function test_validaciones_de_la_dosis(): void
    {
        $id = $this->inicial('POLI');
        $this->dosis('POLI', 1);
        $this->actingAs($this->admin());

        Livewire::test(CatalogoInicialVacunas::class)->call('abrirDosis', $id)
            ->call('guardarDosis')->assertHasErrors('dosis_numero')
            ->set('dosis_numero', '0')->call('guardarDosis')->assertHasErrors('dosis_numero')
            ->set('dosis_numero', '1')->set('meses', '-3')->call('guardarDosis')->assertHasErrors('meses')
            // Número repetido dentro de la misma vacuna.
            ->set('meses', '2')->call('guardarDosis')->assertHasErrors('dosis_numero');

        $this->assertSame(1, DB::table('vacunas_dosis')->whereNull('reg_medico')->where('codi_vacuna', 'POLI')->count());
    }
}
