<?php

namespace Tests\Feature;

use App\Actions\Catalogos\GuardarPercentilInicial;
use App\Clinica\Crecimiento\ReferenciaDeCrecimiento;
use App\Http\Livewire\Admin\CatalogoInicialPercentiles;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La referencia de crecimiento inicial (WEB-7.4.3e, `/admin/catalogo-inicial/percentiles`): sólo Root y Administrador; las reglas de la Action (rangos que no se
 * solapan, sin huecos absurdos, bajo ≤ medio ≤ alto); **opera únicamente sobre las filas sin dueño**; y la lectura del crecimiento sigue prefiriendo la del médico.
 */
class CatalogoInicialPercentilesAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('percentil_nino')->whereNull('reg_medico')->delete();
        DB::table('percentil_nina')->whereNull('reg_medico')->delete();
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Pct', 'admin-pct-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function conRol(string $rol): User
    {
        $user = User::create([
            'name' => $rol, 'email' => strtolower($rol) . '-pct-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function datos(array $extra = []): array
    {
        return array_merge([
            'edad_ini_dia' => '0', 'edad_fin_dia' => '90', 'edad_texto' => 'Recién nacido', 'edad_meses' => '', 'anos' => '',
            'peso_b' => '2,7', 'peso_m' => '3.2', 'peso_a' => '3.8', 'talla_b' => '', 'talla_m' => '', 'talla_a' => '',
        ], $extra);
    }

    private function fila(string $tabla, int $ini, int $fin, ?string $reg = null, array $extra = []): int
    {
        return DB::table($tabla)->insertGetId(array_merge([
            'reg_medico' => $reg, 'edad_ini_dia' => $ini, 'edad_fin_dia' => $fin, 'edad_texto' => "R $ini-$fin",
            'peso_b' => 2, 'peso_m' => 3, 'peso_a' => 4, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    // ------------------------------------------------------------------------------------------ Acceso

    public function test_solo_root_y_administrador_entran(): void
    {
        $this->actingAs($this->admin())->get(route('admin.catalogo-inicial.percentiles'))->assertOk()->assertSee('Referencia de crecimiento');

        foreach (['Medico', 'Secretaria'] as $rol) {
            $this->actingAs($this->conRol($rol))->get(route('admin.catalogo-inicial.percentiles'))->assertForbidden();
        }
        auth()->logout();
        $this->get(route('admin.catalogo-inicial.percentiles'))->assertRedirect();
    }

    // ------------------------------------------------------------------------------------------ La Action

    public function test_el_alta_calcula_los_opcionales_y_acepta_la_coma(): void
    {
        $id = app(GuardarPercentilInicial::class)->ejecutar('nina', $this->datos(['edad_texto' => '']));

        $fila = DB::table('percentil_nina')->where('id', $id)->first();
        $this->assertNull($fila->reg_medico);
        $this->assertEquals(2.7, $fila->peso_b);
        $this->assertSame('Días 0 a 90', $fila->edad_texto);
        $this->assertSame(3, (int) $fila->edad_meses);
        $this->assertEquals(0.0, $fila->anos);
        $this->assertSame(0, DB::table('percentil_nino')->count() - DB::table('percentil_nino')->whereNotNull('reg_medico')->count());
    }

    public function test_los_rangos_se_pueden_tocar_en_el_borde_pero_no_solapar(): void
    {
        $accion = app(GuardarPercentilInicial::class);
        $accion->ejecutar('nino', $this->datos());
        $accion->ejecutar('nino', $this->datos(['edad_ini_dia' => '90', 'edad_fin_dia' => '180', 'edad_texto' => 'Segundo']));

        foreach ([['89', '120'], ['100', '170'], ['0', '300'], ['91', '179']] as [$ini, $fin]) {
            try {
                $accion->ejecutar('nino', $this->datos(['edad_ini_dia' => $ini, 'edad_fin_dia' => $fin]));
                $this->fail("$ini-$fin debería solaparse");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('se solapa', $e->getMessage());
            }
        }

        $this->assertSame(2, DB::table('percentil_nino')->whereNull('reg_medico')->count());
        // En el otro sexo los mismos días están libres.
        $accion->ejecutar('nina', $this->datos());
    }

    public function test_un_hueco_absurdo_se_rechaza_y_uno_normal_se_avisa_en_la_lista(): void
    {
        $accion = app(GuardarPercentilInicial::class);
        $accion->ejecutar('nino', $this->datos());

        try {
            $accion->ejecutar('nino', $this->datos(['edad_ini_dia' => '1000', 'edad_fin_dia' => '1100']));
            $this->fail('debería rechazar el hueco');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('hueco de 910 días', $e->getMessage());
        }

        $accion->ejecutar('nino', $this->datos(['edad_ini_dia' => '120', 'edad_fin_dia' => '200']));
        $huecos = GuardarPercentilInicial::huecos(DB::table('percentil_nino')->whereNull('reg_medico')->get());
        $this->assertSame([['desde' => 90, 'hasta' => 120]], $huecos);
    }

    public function test_valida_el_rango_y_las_medidas(): void
    {
        $accion = app(GuardarPercentilInicial::class);

        $casos = [
            [['edad_ini_dia' => 'x'], 'inicio del rango va en días'],
            [['edad_fin_dia' => '0'], 'fin del rango tiene que ser mayor'],
            [['edad_fin_dia' => '99999'], 'fin del rango va en días'],
            [['peso_m' => '1'], 'bajo tiene que ser menor o igual al medio'],
            [['peso_a' => '3'], 'bajo tiene que ser menor o igual al medio'],
            [['peso_a' => ''], 'completa bajo, medio y alto'],
            [['peso_b' => 'mucho'], 'tiene que ser un número'],
            [['peso_b' => '-1'], 'tiene que ser un número'],
            [['peso_b' => '', 'peso_m' => '', 'peso_a' => ''], 'al menos el peso o la talla'],
            [['edad_meses' => '2,5'], 'meses'],
            [['edad_texto' => str_repeat('x', 101)], 'texto de la edad'],
        ];

        foreach ($casos as [$extra, $mensaje]) {
            try {
                $accion->ejecutar('nino', $this->datos($extra));
                $this->fail("debería rechazar: $mensaje");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('percentil_nino')->whereNull('reg_medico')->count());

        // Todas las medidas completas y en orden se guardan (peso, talla, cefálico, brazo e IMC).
        $accion->ejecutar('nino', $this->datos(['talla_b' => '48', 'talla_m' => '50', 'talla_a' => '52', 'imc_b' => '12', 'imc_m' => '13', 'imc_a' => '14']));
        $this->assertSame(1, DB::table('percentil_nino')->whereNull('reg_medico')->count());
    }

    public function test_editar_no_choca_consigo_misma_y_no_toca_filas_de_un_consultorio(): void
    {
        $accion = app(GuardarPercentilInicial::class);
        $id = $this->fila('percentil_nino', 0, 90);
        $propia = $this->fila('percentil_nino', 0, 90, 'consultorio-x');

        $accion->ejecutar('nino', $this->datos(['peso_a' => '4.5']), $id);
        $this->assertEquals(4.5, DB::table('percentil_nino')->where('id', $id)->value('peso_a'));
        $this->assertEquals(4, DB::table('percentil_nino')->where('id', $propia)->value('peso_a'));

        foreach ([fn () => $accion->ejecutar('nino', $this->datos(), $propia), fn () => $accion->quitar('nino', $propia)] as $intento) {
            try {
                $intento();
                $this->fail('debería rechazar');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('no está en la referencia inicial', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('percentil_nino')->where('id', $propia)->count());

        $accion->quitar('nino', $id);
        $this->assertSame(0, DB::table('percentil_nino')->where('id', $id)->count());
    }

    // ------------------------------------------------------------------------------------------ La lectura

    public function test_el_crecimiento_prefiere_la_referencia_del_medico_y_si_no_la_inicial(): void
    {
        $this->fila('percentil_nino', 0, 90, null, ['peso_m' => 3.3]);
        $propia = $this->fila('percentil_nino', 0, 90, 'consultorio-pct', ['peso_m' => 3.9, 'peso_a' => 5]);

        $con = app(ReferenciaDeCrecimiento::class)->cargar('consultorio-pct');
        $sin = app(ReferenciaDeCrecimiento::class)->cargar('otro-consultorio');

        $this->assertEquals(3.9, $con->filaDe('nino', 30)['peso_m']);
        $this->assertEquals(3.3, $sin->filaDe('nino', 30)['peso_m']);
        $this->assertGreaterThan(0, $propia);
    }

    // ------------------------------------------------------------------------------------------ El componente

    public function test_el_componente_crea_edita_y_quita_con_sus_errores_bajo_el_campo(): void
    {
        $this->actingAs($this->admin());
        $id = $this->fila('percentil_nino', 0, 90);

        Livewire::test(CatalogoInicialPercentiles::class)
            ->assertSee('Niños (1)')->assertSee('R 0-90')
            ->call('abrir')
            ->set('f.edad_ini_dia', '50')->set('f.edad_fin_dia', '120')->set('f.peso_b', '1')->set('f.peso_m', '2')->set('f.peso_a', '3')
            ->call('guardar')
            ->assertHasErrors(['f.edad_fin_dia'])
            ->set('f.edad_ini_dia', '90')->set('f.peso_a', '1')
            ->call('guardar')
            ->assertHasErrors(['f.medidas'])
            ->set('f.peso_a', '3')
            ->call('guardar')
            ->assertHasNoErrors()->assertSet('modal', null)
            ->call('abrir', $id)->assertSet('f.peso_m', '3')
            ->set('f.peso_a', '5')->call('guardar')->assertHasNoErrors()
            ->call('elegir', 'nina')->assertSee('Niñas (0)')
            ->call('elegir', 'nino')->call('abrirBorrar', $id)->call('borrar')
            ->assertSet('modal', null);

        $this->assertSame(0, DB::table('percentil_nino')->where('id', $id)->count());
        $this->assertSame(1, DB::table('percentil_nino')->whereNull('reg_medico')->count());
    }

    public function test_el_componente_no_abre_una_fila_de_un_consultorio(): void
    {
        $this->actingAs($this->admin());
        $propia = $this->fila('percentil_nino', 0, 90, 'consultorio-x');

        Livewire::test(CatalogoInicialPercentiles::class)->call('abrir', $propia)->assertStatus(404);
    }
}
