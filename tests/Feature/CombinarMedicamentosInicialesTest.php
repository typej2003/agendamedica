<?php

namespace Tests\Feature;

use App\Actions\Catalogos\CombinarMedicamentosIniciales;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales as Registro;
use App\Clinica\Catalogos\Iniciales\EspecialidadesDelCatalogoInicial as Marcas;
use App\Http\Livewire\Admin\CatalogosIniciales;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Combinar medicamentos duplicados del catálogo inicial**: las fichas marcadas pasan a ser una (la que se conserva), con los textos elegidos, las especialidades
 * sumadas y los tratamientos iniciales apuntando a la que queda.
 */
class CombinarMedicamentosInicialesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Registro::todos() as $def) {
            DB::table($def['tabla'])->whereNull('reg_medico')->delete();
        }
        DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->delete();
        DB::table('catalogo_inicial_especialidad')->delete();
    }

    private function esp(string $slug): Specialty
    {
        return Specialty::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
    }

    private function ficha(string $codigo, string $nombre, string $presentacion = '', array $especialidades = [], array $mas = []): int
    {
        $id = DB::table('vademecum')->insertGetId([
            'codemedicina' => $codigo, 'nombrecomercial' => $nombre, 'presentacion' => $presentacion,
            'reg_medico' => null, 'created_at' => now(), 'updated_at' => now(),
        ] + $mas);
        Marcas::asignar('medicamentos', $id, array_map(fn (Specialty $e) => $e->id, $especialidades));

        return $id;
    }

    private function datos(array $cambios = []): array
    {
        return $cambios + ['nombrecomercial' => 'TEGRETOL', 'nombregenerico' => '', 'presentacion' => 'TABLETAS 200MG', 'nombrecomercial1' => '', 'nombrecomercial2' => '',
            'nombrecomercial3' => '', 'uso' => 'TOMAR 1 CADA 12 HORAS', 'dosificacion' => false, 'dosis' => '', 'cada' => '', 'durante' => '', 'pvc' => '', 'pvs' => '', 'concentracion' => ''];
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Comb', 'admin-comb-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    public function test_combina_las_fichas_con_los_textos_elegidos_y_suma_las_especialidades(): void
    {
        $oft = $this->esp('oftalmologia');
        $neu = $this->esp('neurologia');
        $a = $this->ficha('0001', 'TEGRETOL', 'TABLETAS 200 MG', [$oft]);
        $b = $this->ficha('0002', 'TEGRETOL ', 'TAB 200MG', [$neu]);
        $c = $this->ficha('0003', 'ATAMEL', 'GOTAS');

        $descartadas = app(CombinarMedicamentosIniciales::class)->ejecutar([$a, $b], $a, $this->datos(), [$oft->id, $neu->id]);

        $this->assertSame(1, $descartadas);
        $this->assertSame([1, 3], DB::table('vademecum')->whereNull('reg_medico')->orderBy('codemedicina')->pluck('codemedicina')->map(fn ($c) => (int) $c)->all());
        $quedo = DB::table('vademecum')->where('id', $a)->first();
        $this->assertSame('TABLETAS 200MG', $quedo->presentacion);
        $this->assertSame('TOMAR 1 CADA 12 HORAS', $quedo->uso);
        $this->assertEqualsCanonicalizing([$oft->id, $neu->id], Marcas::de('medicamentos', [$a])[$a]);
        $this->assertSame([], Marcas::de('medicamentos', [$b]));
        $this->assertNotNull(DB::table('vademecum')->where('id', $c)->first());
    }

    public function test_los_tratamientos_iniciales_pasan_a_la_ficha_que_queda_sin_repetirla(): void
    {
        $a = $this->ficha('0001', 'TEGRETOL', 'A');
        $b = $this->ficha('0002', 'TEGRETOL', 'B');
        $linea = fn ($tratamiento, $med, $orden) => DB::table('recipe_grupo_detalle')->insertGetId([
            'codigo' => $tratamiento, 'codemedicina' => $med, 'cantidad' => 1, 'orden' => $orden, 'reg_medico' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $soloB = $linea('T1', '0002', 1);
        $ambas1 = $linea('T2', '0001', 1);
        $ambas2 = $linea('T2', '0002', 2);

        app(CombinarMedicamentosIniciales::class)->ejecutar([$a, $b], $a, $this->datos(), []);

        $this->assertSame('0001', trim(DB::table('recipe_grupo_detalle')->where('id', $soloB)->value('codemedicina')));
        $this->assertNotNull(DB::table('recipe_grupo_detalle')->where('id', $ambas1)->first());
        $this->assertNull(DB::table('recipe_grupo_detalle')->where('id', $ambas2)->first());
    }

    public function test_si_algo_no_valida_no_cambia_nada(): void
    {
        $a = $this->ficha('0001', 'TEGRETOL', 'A');
        $b = $this->ficha('0002', 'TEGRETOL', 'B');

        try {
            app(CombinarMedicamentosIniciales::class)->ejecutar([$a, $b], $a, $this->datos(['nombrecomercial' => '']), []);
            $this->fail('Tenía que pedir el nombre.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nombre comercial', mb_strtolower($e->getMessage()));
        }

        $this->assertSame(2, DB::table('vademecum')->whereNull('reg_medico')->count());
    }

    public function test_pide_al_menos_dos_fichas_y_que_la_conservada_este_entre_ellas(): void
    {
        $a = $this->ficha('0001', 'A');
        $b = $this->ficha('0002', 'B');
        $c = $this->ficha('0003', 'C');
        $accion = app(CombinarMedicamentosIniciales::class);

        foreach ([[[$a], $a], [[$a, $b], $c]] as [$ids, $queda]) {
            try {
                $accion->ejecutar($ids, $queda, $this->datos(), []);
                $this->fail('Tenía que rechazarlo.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(3, DB::table('vademecum')->whereNull('reg_medico')->count());
    }

    public function test_la_pantalla_marca_propone_los_textos_y_combina(): void
    {
        $this->actingAs($this->admin());
        $oft = $this->esp('oftalmologia');
        $neu = $this->esp('neurologia');
        $a = $this->ficha('0001', 'TEGRETOL', 'TABLETAS 200 MG', [$oft], ['uso' => 'UNA CADA 12 HORAS']);
        $b = $this->ficha('0002', 'TEGRETOL', 'TAB 200MG', [$neu], ['uso' => 'TOMAR 1 TABLETA CADA 12 HORAS']);

        $componente = Livewire::test(CatalogosIniciales::class, ['catalogo' => 'medicamentos'])
            ->set('seleccion', [(string) $a])->call('abrirCombinar')->assertSet('modal', null)
            ->set('seleccion', [(string) $a, (string) $b])->call('abrirCombinar')
            ->assertSet('modal', 'combinar')->assertSee('Los textos difieren')
            ->assertSet('c.presentacion', 'TABLETAS 200 MG')->assertSet('c.nombrecomercial', 'TEGRETOL');

        $this->assertEqualsCanonicalizing([(string) $oft->id, (string) $neu->id], $componente->get('cAplica'));

        $opciones = $componente->get('combinar')['opciones']['uso'];
        $this->assertContains('TOMAR 1 TABLETA CADA 12 HORAS', $opciones);
        $componente->set('c.uso', 'TOMAR 1 TABLETA CADA 12 HORAS')
            ->call('combinarFichas')->assertSet('modal', null)->assertSet('seleccion', []);

        $this->assertSame(1, DB::table('vademecum')->whereNull('reg_medico')->count());
        $this->assertSame('TOMAR 1 TABLETA CADA 12 HORAS', DB::table('vademecum')->where('id', $a)->value('uso'));
    }

    public function test_sin_permiso_de_administrador_no_entra(): void
    {
        $this->actingAs(User::create(['name' => 'Otro', 'email' => 'otro-' . uniqid() . '@example.com', 'password' => 'x', 'is_active' => true]));

        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'medicamentos'])->assertForbidden();
    }
}
