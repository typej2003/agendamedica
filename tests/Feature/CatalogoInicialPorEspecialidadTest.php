<?php

namespace Tests\Feature;

use App\Actions\Catalogos\CargarCatalogosInicialesAlMedico;
use App\Actions\Catalogos\CopiarCatalogoInicial;
use App\Actions\Catalogos\GuardarFilaDeCatalogoInicial;
use App\Actions\Catalogos\QuitarFilaDeCatalogoInicial;
use App\Actions\Catalogos\SembrarCatalogosIniciales;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales as Registro;
use App\Clinica\Catalogos\Iniciales\EspecialidadesDelCatalogoInicial as Marcas;
use App\Http\Livewire\Admin\CatalogosIniciales;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **El catálogo inicial por especialidad** (WEB-12.9): el puente `catalogo_inicial_especialidad` (sin marca = general), la copia filtrada por las especialidades del
 * médico, el ABM del administrador (filtro, marcas y «Aplica a»), la siembra desde los archivos del escritorio (`catalogos:sembrar-iniciales`) y la copia automática
 * al asignarle una especialidad a un médico nuevo.
 */
class CatalogoInicialPorEspecialidadTest extends TestCase
{
    use DatabaseTransactions;

    private string $carpeta = '';

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

    protected function tearDown(): void
    {
        if ($this->carpeta !== '') {
            File::deleteDirectory($this->carpeta);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------ Ayudas

    private function esp(string $slug): Specialty
    {
        return Specialty::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
    }

    private function ini(string $tabla, array $fila, array $especialidades = []): int
    {
        $id = DB::table($tabla)->insertGetId($fila + ['reg_medico' => null, 'created_at' => now(), 'updated_at' => now()]);
        $slug = collect(Registro::todos())->search(fn ($d) => $d['tabla'] === $tabla);
        Marcas::asignar($slug, $id, array_map(fn (Specialty $e) => $e->id, $especialidades));

        return $id;
    }

    /** @return array{0:User, 1:Medico} */
    private function medico(array $especialidades = []): array
    {
        $reg = 'cat-esp-' . substr(uniqid(), -6);
        $user = User::create(['name' => 'Usuario', 'email' => $reg . '@example.com', 'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false]);
        $user->assignRole('Medico');
        $medico = Medico::create(['user_id' => $user->id, 'name' => 'Dra', 'lastname' => 'Especial', 'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg]);
        $medico->specialties()->attach(array_map(fn (Specialty $e) => $e->id, $especialidades));
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico];
    }

    private function propias(string $tabla, Medico $medico, string $columna): array
    {
        return DB::table($tabla)->where('reg_medico', $medico->reg_medico)->orderBy($columna)->pluck($columna)->map(fn ($v) => trim((string) $v))->all();
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Esp', 'admin-esp-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /** @param  array<string,array<string,array<int,array<string,mixed>>>>  $archivos  `especialidad => catalogo => filas` */
    private function archivos(array $archivos): string
    {
        $this->carpeta = storage_path('framework/testing/catalogos-iniciales-' . uniqid());
        foreach ($archivos as $especialidad => $catalogos) {
            File::ensureDirectoryExists($this->carpeta . '/' . $especialidad);
            foreach ($catalogos as $catalogo => $filas) {
                File::put($this->carpeta . '/' . $especialidad . '/' . $catalogo . '.json', json_encode($filas));
            }
        }

        return $this->carpeta;
    }

    // ------------------------------------------------------------------------------------------ La copia filtrada

    public function test_el_medico_recibe_lo_general_y_lo_de_sus_especialidades(): void
    {
        $ped = $this->esp('pediatria');
        $car = $this->esp('cardiologia');
        $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'ATAMEL']);
        $this->ini('vademecum', ['codemedicina' => '0002', 'nombrecomercial' => 'AMOXIL'], [$ped]);
        $this->ini('vademecum', ['codemedicina' => '0003', 'nombrecomercial' => 'ASPIRINA'], [$car]);
        $this->ini('vademecum', ['codemedicina' => '0004', 'nombrecomercial' => 'IBUPROFENO'], [$ped, $car]);
        [, $medico] = $this->medico([$ped]);

        $copiar = app(CopiarCatalogoInicial::class);
        $this->assertSame(['AMOXIL', 'ATAMEL', 'IBUPROFENO'], $copiar->novedades('medicamentos', [$medico->reg_medico], [$ped->id])->pluck('nombrecomercial')->sort()->values()->all());
        $this->assertSame(['ATAMEL'], $copiar->novedades('medicamentos', [$medico->reg_medico], [])->pluck('nombrecomercial')->all());
        $this->assertCount(4, $copiar->novedades('medicamentos', [$medico->reg_medico]));

        $copiar->ejecutar('medicamentos', $medico->reg_medico, [$medico->reg_medico], [$ped->id]);
        $this->assertSame(['AMOXIL', 'ATAMEL', 'IBUPROFENO'], $this->propias('vademecum', $medico, 'nombrecomercial'));
    }

    public function test_las_dependencias_no_se_filtran(): void
    {
        $ped = $this->esp('pediatria');
        $car = $this->esp('cardiologia');
        $this->ini('tipos_examenes', ['codetipo' => '001', 'tipo' => 'ECOCARDIOGRAMA'], [$car]);
        $this->ini('examenes', ['codeexamen' => '001', 'examen' => 'ECO PEDIATRICO', 'codetipo' => '001'], [$ped]);
        [, $medico] = $this->medico([$ped]);

        $r = app(CopiarCatalogoInicial::class)->ejecutar('examenes', $medico->reg_medico, [$medico->reg_medico], [$ped->id]);

        $this->assertSame(1, $r['copiadas']);
        $this->assertSame(1, $r['dependencias']);
        $this->assertSame(['ECOCARDIOGRAMA'], $this->propias('tipos_examenes', $medico, 'tipo'));
    }

    public function test_la_pantalla_del_medico_usa_las_especialidades_del_contexto(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $ped = $this->esp('pediatria');
        $gin = $this->esp('ginecologia-y-obstetricia');
        $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'ASMA'], [$ped]);
        $this->ini('diagnosticos', ['codediagnostico' => '0002', 'descripcion' => 'MIOMA'], [$gin]);
        [$user, $medico] = $this->medico([$ped]);
        $sesion = fn () => $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $ped->id, 'office_id' => null]])->actingAs($user);

        $sesion()->get(route('clinica.catalogos.diagnosticos'))->assertOk()->assertSee('ASMA')->assertDontSee('MIOMA');
        $sesion()->post(route('clinica.catalogos.cargar-inicial', 'diagnosticos'))->assertSessionHas('estado');

        $this->assertSame(['ASMA'], $this->propias('diagnosticos', $medico, 'descripcion'));
    }

    // ------------------------------------------------------------------------------------------ El ABM del administrador

    public function test_guardar_y_quitar_manejan_las_marcas(): void
    {
        $ped = $this->esp('pediatria');
        $car = $this->esp('cardiologia');
        $accion = app(GuardarFilaDeCatalogoInicial::class);

        $id = $accion->ejecutar('diagnosticos', ['descripcion' => 'asma'], null, [$ped->id, (string) $car->id]);
        $this->assertEqualsCanonicalizing([$ped->id, $car->id], Marcas::de('diagnosticos', [$id])[$id]);

        // Sin `especialidades` (null) no se tocan; con [] la fila queda general.
        $accion->ejecutar('diagnosticos', ['descripcion' => 'asma leve'], $id);
        $this->assertCount(2, Marcas::de('diagnosticos', [$id])[$id]);
        $accion->ejecutar('diagnosticos', ['descripcion' => 'asma leve'], $id, []);
        $this->assertSame([], Marcas::de('diagnosticos', [$id]));

        try {
            $accion->ejecutar('diagnosticos', ['descripcion' => 'otitis'], null, [999999]);
            $this->fail('Aceptó una especialidad que no existe.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Esa especialidad no existe.', $e->getMessage());
        }
        $this->assertSame(0, DB::table('diagnosticos')->whereNull('reg_medico')->where('descripcion', 'OTITIS')->count());

        $accion->ejecutar('diagnosticos', ['descripcion' => 'asma leve'], $id, [$ped->id]);
        app(QuitarFilaDeCatalogoInicial::class)->ejecutar('diagnosticos', $id);
        $this->assertSame(0, DB::table('catalogo_inicial_especialidad')->where('catalogo', 'diagnosticos')->where('fila_id', $id)->count());
    }

    public function test_el_componente_filtra_muestra_y_guarda_las_especialidades(): void
    {
        $this->actingAs($this->admin());
        $ped = $this->esp('pediatria');
        $car = $this->esp('cardiologia');
        $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'FIEBRE']);
        $asma = $this->ini('diagnosticos', ['codediagnostico' => '0002', 'descripcion' => 'ASMA'], [$ped]);
        $this->ini('diagnosticos', ['codediagnostico' => '0003', 'descripcion' => 'ARRITMIA'], [$car]);

        $componente = Livewire::test(CatalogosIniciales::class, ['catalogo' => 'diagnosticos'])
            ->assertSee('FIEBRE')->assertSee('ASMA')->assertSee('ARRITMIA')->assertSee('General')
            ->set('especialidad', 'general')->assertSee('FIEBRE')->assertDontSee('ASMA')->assertDontSee('ARRITMIA')
            ->set('especialidad', (string) $ped->id)->assertSee('ASMA')->assertDontSee('FIEBRE')->assertDontSee('ARRITMIA');

        // Con el filtro en una especialidad, lo nuevo nace marcado con ella.
        $componente->call('abrir')->assertSet('aplica', [(string) $ped->id])
            ->set('f.descripcion', 'bronquiolitis')->call('guardar')->assertHasNoErrors();
        $nuevo = (int) DB::table('diagnosticos')->whereNull('reg_medico')->where('descripcion', 'BRONQUIOLITIS')->value('id');
        $this->assertSame([$ped->id], Marcas::de('diagnosticos', [$nuevo])[$nuevo]);

        $componente->call('abrir', $asma)->assertSet('aplica', [(string) $ped->id])
            ->set('aplica', [(string) $ped->id, (string) $car->id])->call('guardar')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$ped->id, $car->id], Marcas::de('diagnosticos', [$asma])[$asma]);
    }

    public function test_el_admin_configura_la_dosis_por_kilo_y_el_medico_la_recibe(): void
    {
        $this->actingAs($this->admin());
        $ped = $this->esp('pediatria');

        $componente = Livewire::test(CatalogosIniciales::class, ['catalogo' => 'medicamentos'])
            ->call('abrir')->assertSee('La dosis es por kilo (récipe de pediatría)')->assertSee('Gotas por ml')
            ->assertSet('f.dosificacion', false)->assertSee('La dosis es por kilo')
            ->set('f.nombrecomercial', 'zoxaval')->set('f.presentacion', 'SUSP 30ml')->set('f.dosificacion', true)
            ->set('f.dosis', 'siete')->call('guardar')->assertHasErrors(['f.formulario']);
        $componente->set('f.dosis', '-1')->call('guardar')->assertHasErrors(['f.formulario']);

        $componente->set('f.dosis', '7,5')->set('f.cada', '2')->set('f.durante', '3')->set('f.pvc', '100')->set('f.pvs', '5')->set('f.concentracion', '')
            ->set('aplica', [(string) $ped->id])->call('guardar')->assertHasNoErrors()
            ->assertSee('Por kilo: 7,5 mg/kg');

        $fila = DB::table('vademecum')->whereNull('reg_medico')->where('nombrecomercial', 'ZOXAVAL')->first();
        $this->assertSame('SI', $fila->dosificacion);
        $this->assertEquals([7.5, 2.0, 3.0, 100.0, 5.0, null], [$fila->dosis, $fila->cada, $fila->durante, $fila->pvc, $fila->pvs, $fila->concentracion]);

        // Editar trae los números al formulario.
        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'medicamentos'])->call('abrir', $fila->id)
            ->assertSet('f.dosificacion', true)->assertSet('f.dosis', '7.5')->assertSet('f.pvc', '100');

        // Destildar quita el SI; una nota vieja del escritorio en la dosificación no se pierde.
        $nota = $this->ini('vademecum', ['codemedicina' => '0900', 'nombrecomercial' => 'MEDROL', 'dosificacion' => '25-50 mg/kg/dia']);
        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'medicamentos'])
            ->call('abrir', $fila->id)->set('f.dosificacion', false)->call('guardar')->assertHasNoErrors()
            ->call('abrir', $nota)->assertSet('f.dosificacion', false)->set('f.presentacion', 'TAB')->call('guardar')->assertHasNoErrors();
        $this->assertNull(DB::table('vademecum')->where('id', $fila->id)->value('dosificacion'));
        $this->assertSame('25-50 mg/kg/dia', DB::table('vademecum')->where('id', $nota)->value('dosificacion'));
        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'medicamentos'])
            ->call('abrir', $fila->id)->set('f.dosificacion', true)->call('guardar')->assertHasNoErrors();

        // El pediatra que lo carga se lleva la configuración.
        [, $medico] = $this->medico([$ped]);
        app(CopiarCatalogoInicial::class)->ejecutar('medicamentos', $medico->reg_medico, [$medico->reg_medico], [$ped->id]);
        $copia = DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'ZOXAVAL')->first();
        $this->assertSame('SI', $copia->dosificacion);
        $this->assertEquals([7.5, 2.0, 3.0, 100.0, 5.0], [$copia->dosis, $copia->cada, $copia->durante, $copia->pvc, $copia->pvs]);
    }

    // ------------------------------------------------------------------------------------------ La siembra

    public function test_la_siembra_agrupa_marca_traduce_codigos_y_es_idempotente(): void
    {
        $ped = $this->esp('pediatria');
        $car = $this->esp('cardiologia');
        $oft = $this->esp('oftalmologia');
        // Una general que ya existía (sin marca) queda general aunque la traiga una sola especialidad.
        $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'FIEBRE']);

        $carpeta = $this->archivos([
            'pediatria' => [
                'medicamentos' => [['codigo_origen' => '0403', 'nombrecomercial' => 'atamel', 'presentacion' => 'GOTAS'], ['codigo_origen' => '0405', 'nombrecomercial' => 'AMOXIL', 'presentacion' => 'SUSP']],
                'diagnosticos' => [['codigo_origen' => '0172', 'descripcion' => 'Fiebre'], ['codigo_origen' => '0173', 'descripcion' => 'ASMA']],
                'tipos-examen' => [['codigo_origen' => '001', 'tipo' => 'LABORATORIO']],
                'examenes' => [['codigo_origen' => '041', 'examen' => 'HEMOGRAMA', 'codetipo' => '001']],
                'plantillas-texto' => [['codigo_origen' => '0001', 'titulo' => 'VOMITO', 'descripcion' => 'Texto', 'tipo' => 'EA'], ['codigo_origen' => '0002', 'titulo' => 'SIN TIPO', 'descripcion' => 'x']],
            ],
            'cardiologia' => [
                'medicamentos' => [['codigo_origen' => '0090', 'nombrecomercial' => 'ATAMEL', 'presentacion' => 'gotas']],
                'tipos-examen' => [['codigo_origen' => '007', 'tipo' => 'Laboratorio']],
                'examenes' => [['codigo_origen' => '500', 'examen' => 'HEMOGRAMA', 'codetipo' => '007']],
            ],
            'oftalmologia' => [
                'medicamentos' => [['codigo_origen' => '0001', 'nombrecomercial' => 'ATAMEL', 'presentacion' => 'GOTAS'], ['codigo_origen' => '0089', 'nombrecomercial' => 'SOPHIPREN', 'presentacion' => 'COLIRIO']],
                'tipos-examen' => [['codigo_origen' => '001', 'tipo' => 'LABORATORIO']],
                'tratamientos' => [['codigo_origen' => '0002', 'tratamiento' => 'LOTESOFT', 'lineas' => [
                    ['nombrecomercial' => 'SOPHIPREN', 'presentacion' => 'COLIRIO', 'descripcion' => 'SOPHIPREN/COLIRIO', 'indicaciones' => 'una gota', 'cantidad' => '1', 'orden' => '0'],
                    ['nombrecomercial' => 'NO ESTA', 'descripcion' => 'NO ESTA', 'cantidad' => '1', 'orden' => '1'],
                ]]],
            ],
            'no-existe' => ['diagnosticos' => [['codigo_origen' => '1', 'descripcion' => 'X']]],
        ]);

        $sembrar = app(SembrarCatalogosIniciales::class);

        // Simular no escribe nada.
        $sembrar->ejecutar($carpeta, true);
        $this->assertSame(0, DB::table('vademecum')->whereNull('reg_medico')->count());

        $r = $sembrar->ejecutar($carpeta);
        $this->assertSame(['no-existe'], $r['sin_especialidad']);

        // ATAMEL está en las tres: una sola fila, general. AMOXIL sólo en pediatría.
        $atamel = DB::table('vademecum')->whereNull('reg_medico')->where('nombrecomercial', 'ATAMEL')->get();
        $this->assertCount(1, $atamel);
        $this->assertSame([], Marcas::de('medicamentos', [(int) $atamel[0]->id]));
        $amoxil = (int) DB::table('vademecum')->whereNull('reg_medico')->where('nombrecomercial', 'AMOXIL')->value('id');
        $this->assertSame([$ped->id], Marcas::de('medicamentos', [$amoxil])[$amoxil]);

        // FIEBRE ya era general: no se duplica ni se marca. ASMA es nueva y de pediatría.
        $this->assertSame(1, DB::table('diagnosticos')->whereNull('reg_medico')->where('descripcion', 'FIEBRE')->count());
        $fiebre = (int) DB::table('diagnosticos')->whereNull('reg_medico')->where('descripcion', 'FIEBRE')->value('id');
        $this->assertSame([], Marcas::de('diagnosticos', [$fiebre]));
        $this->assertSame('0002', trim((string) DB::table('diagnosticos')->whereNull('reg_medico')->where('descripcion', 'ASMA')->value('codediagnostico')));

        // HEMOGRAMA de laboratorio viene de dos bases con códigos distintos: una fila, apuntando al tipo inicial, con las dos marcas.
        $tipo = DB::table('tipos_examenes')->whereNull('reg_medico')->get();
        $this->assertCount(1, $tipo);
        $hemograma = DB::table('examenes')->whereNull('reg_medico')->where('examen', 'HEMOGRAMA')->get();
        $this->assertCount(1, $hemograma);
        $this->assertSame(trim($tipo[0]->codetipo), trim($hemograma[0]->codetipo));
        $this->assertEqualsCanonicalizing([$ped->id, $car->id], Marcas::de('examenes', [(int) $hemograma[0]->id])[(int) $hemograma[0]->id]);

        // La plantilla sin tipo conocido no entra.
        $this->assertSame(['VOMITO'], DB::table('formato_print')->whereNull('reg_medico')->pluck('titulo')->all());

        // La línea del tratamiento apunta al SOPHIPREN inicial; la que no tiene medicamento se omite y se informa.
        $codigoTrat = DB::table('recipe_grupo')->whereNull('reg_medico')->where('tratamiento', 'LOTESOFT')->value('codigo');
        $lineas = DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('codigo', $codigoTrat)->get();
        $this->assertCount(1, $lineas);
        $this->assertSame(trim((string) DB::table('vademecum')->whereNull('reg_medico')->where('nombrecomercial', 'SOPHIPREN')->value('codemedicina')), trim($lineas[0]->codemedicina));
        $this->assertNotEmpty(array_filter($r['omitidas'], fn ($t) => str_contains($t, 'LOTESOFT')));
        $this->assertSame([$oft->id], Marcas::de('tratamientos', [(int) DB::table('recipe_grupo')->whereNull('reg_medico')->value('id')])[(int) DB::table('recipe_grupo')->whereNull('reg_medico')->value('id')]);

        // Otra vez: no cambia nada.
        $antes = DB::table('catalogo_inicial_especialidad')->count();
        $r2 = $sembrar->ejecutar($carpeta);
        $this->assertSame(0, array_sum(array_column($r2['catalogos'], 'nuevas')));
        $this->assertSame(0, array_sum(array_column($r2['catalogos'], 'marcas')));
        $this->assertSame($antes, DB::table('catalogo_inicial_especialidad')->count());
    }

    public function test_la_linea_del_tratamiento_va_al_medicamento_con_su_nombre_y_presentacion_exactos(): void
    {
        $ped = $this->esp('pediatria');
        $carpeta = $this->archivos(['pediatria' => [
            'medicamentos' => [
                ['codigo_origen' => '0826', 'nombrecomercial' => 'BRUGESIC FORTE', 'presentacion' => 'SUSP 200MG'],
                ['codigo_origen' => '0827', 'nombrecomercial' => 'BRUGESIC PEDIATRICO', 'presentacion' => 'SUSP 100MG'],
                // El que agregó el preparador con el texto de la línea: no trae código del escritorio.
                ['codigo_origen' => '', 'nombrecomercial' => 'BRUGESIC PEDIATRICO', 'presentacion' => 'SUSP 200MG'],
                ['codigo_origen' => '0026', 'nombrecomercial' => 'FULGRAM', 'presentacion' => 'SUSP 600 MG'],
            ],
            'tratamientos' => [['codigo_origen' => '0001', 'tratamiento' => 'NEUMONIA', 'lineas' => [
                ['nombrecomercial' => 'brugesic  pediatrico', 'presentacion' => 'susp 200mg', 'descripcion' => 'BRUGESIC  PEDIATRICO/SUSP 200MG', 'cantidad' => '1', 'orden' => '0'],
                ['nombrecomercial' => 'FULGRAM', 'presentacion' => 'SUSP 600 MG', 'descripcion' => 'FULGRAM/SUSP 600 MG', 'cantidad' => '1', 'orden' => '1'],
                // Parecido a FULGRAM pero no igual: no se vincula a él.
                ['nombrecomercial' => 'FULGRAN', 'presentacion' => 'SUSP 600', 'descripcion' => 'FULGRAN/SUSP 600', 'cantidad' => '1', 'orden' => '2'],
            ]]],
        ]]);

        $r = app(SembrarCatalogosIniciales::class)->ejecutar($carpeta);

        $medicamento = fn ($codigo) => DB::table('vademecum')->whereNull('reg_medico')->where('codemedicina', $codigo)->first(['nombrecomercial', 'presentacion']);
        $lineas = DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->orderBy('orden')->get()->map(fn ($l) => (array) $medicamento($l->codemedicina))->all();
        $this->assertSame([
            ['nombrecomercial' => 'BRUGESIC PEDIATRICO', 'presentacion' => 'SUSP 200MG'],
            ['nombrecomercial' => 'FULGRAM', 'presentacion' => 'SUSP 600 MG'],
        ], $lineas);
        $this->assertNotEmpty(array_filter($r['omitidas'], fn ($t) => str_contains($t, 'FULGRAN/SUSP 600')));

        // Reemplazar rehace el tratamiento con las líneas del archivo (el que ya estaba no recibe líneas nuevas al sembrar).
        $codigo = DB::table('recipe_grupo')->whereNull('reg_medico')->value('codigo');
        DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('codigo', $codigo)->delete();
        app(SembrarCatalogosIniciales::class)->ejecutar($carpeta, false, ['pediatria']);
        $this->assertSame(1, DB::table('recipe_grupo')->whereNull('reg_medico')->count());
        $this->assertSame(2, DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->count());
        $id = (int) DB::table('recipe_grupo')->whereNull('reg_medico')->value('id');
        $this->assertSame([$ped->id], Marcas::de('tratamientos', [$id])[$id]);
    }

    public function test_el_comando_simula_sin_escribir(): void
    {
        $this->esp('pediatria');
        $carpeta = $this->archivos(['pediatria' => ['diagnosticos' => [['codigo_origen' => '1', 'descripcion' => 'ASMA']]]]);

        $this->artisan('catalogos:sembrar-iniciales', ['--simular' => true, '--carpeta' => $carpeta])->assertExitCode(0);
        $this->assertSame(0, DB::table('diagnosticos')->whereNull('reg_medico')->count());

        $this->artisan('catalogos:sembrar-iniciales', ['--carpeta' => $carpeta])->assertExitCode(0);
        $this->assertSame(1, DB::table('diagnosticos')->whereNull('reg_medico')->count());
    }

    public function test_el_admin_carga_antecedentes_eligiendo_su_tipo_y_ve_las_dietas(): void
    {
        $this->actingAs($this->admin());
        $this->ini('antecedentes', ['codantecedente' => '0001', 'descripcion' => 'ASMA BRONQUIAL', 'codtipo' => '02']);
        $this->ini('dietas', ['cod_dieta' => '01', 'dietas' => 'ABLACTACION SOPAS', 'descripcion' => 'Sopas de verduras']);

        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'antecedentes'])
            ->assertSee('ASMA BRONQUIAL')->assertSee('C.A.')
            ->call('abrir')->assertSee('PERINATALES')->assertSee('D.P.')
            ->set('f.descripcion', 'varicela')->set('f.codtipo', '05')->call('guardar')->assertHasNoErrors();
        $this->assertSame('05', trim((string) DB::table('antecedentes')->whereNull('reg_medico')->where('descripcion', 'VARICELA')->value('codtipo')));

        Livewire::test(CatalogosIniciales::class, ['catalogo' => 'dietas'])->assertSee('ABLACTACION SOPAS')->assertSee('Sopas de verduras');

        // La tarjeta del médico concuerda en género: «dietas nuevas».
        $tarjeta = new \App\Clinica\Catalogos\Vistas\NovedadesDeCatalogoVista('Dietas', 'dieta', 'dietas', collect([(object) ['dietas' => 'A'], (object) ['dietas' => 'B']]), '/x', true);
        $this->assertStringStartsWith('Hay 2 dietas nuevas', $tarjeta->texto());
    }

    public function test_reemplazar_una_especialidad_le_quita_lo_que_su_archivo_ya_no_trae(): void
    {
        $ped = $this->esp('pediatria');
        $gin = $this->esp('ginecologia-y-obstetricia');

        // Lo que dejó la siembra anterior.
        $viejo = $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'VIEJO'], [$ped]);
        $compartido = $this->ini('diagnosticos', ['codediagnostico' => '0002', 'descripcion' => 'COMPARTIDO'], [$ped, $gin]);
        $general = $this->ini('diagnosticos', ['codediagnostico' => '0003', 'descripcion' => 'GENERAL']);
        $asma = $this->ini('diagnosticos', ['codediagnostico' => '0004', 'descripcion' => 'ASMA'], [$ped]);
        $deGine = $this->ini('diagnosticos', ['codediagnostico' => '0005', 'descripcion' => 'DE GINE'], [$gin]);
        // Un medicamento sólo de pediatría que un tratamiento de ginecología lleva: no se puede quitar.
        $usado = $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'USADO', 'presentacion' => 'TAB'], [$ped]);
        $this->ini('recipe_grupo', ['codigo' => '0001', 'tratamiento' => 'DE GINE'], [$gin]);
        DB::table('recipe_grupo_detalle')->insert(['reg_medico' => null, 'codigo' => '0001', 'codemedicina' => '0001', 'descripcion' => 'USADO/TAB', 'cantidad' => 1, 'orden' => 0]);
        // Lo que un médico ya copió no se toca.
        DB::table('diagnosticos')->insert(['reg_medico' => 'copia-ped', 'codediagnostico' => '0001', 'descripcion' => 'VIEJO']);

        $carpeta = $this->archivos(['pediatria' => ['diagnosticos' => [
            ['codigo_origen' => '0172', 'descripcion' => 'Asma'], ['codigo_origen' => '0173', 'descripcion' => 'NUEVO'],
        ]]]);

        $r = app(SembrarCatalogosIniciales::class)->ejecutar($carpeta, false, ['pediatria']);

        $this->assertSame(['quitadas' => 1, 'desmarcadas' => 1, 'conservadas' => 0], $r['retiradas']['pediatria']['diagnosticos']);
        $this->assertSame(['quitadas' => 0, 'desmarcadas' => 0, 'conservadas' => 1], $r['retiradas']['pediatria']['medicamentos']);
        $this->assertNotEmpty(array_filter($r['omitidas'], fn ($t) => str_contains($t, 'USADO')));

        $this->assertFalse(DB::table('diagnosticos')->where('id', $viejo)->exists());
        $this->assertSame([], Marcas::de('diagnosticos', [$viejo]));
        $this->assertSame([$gin->id], Marcas::de('diagnosticos', [$compartido])[$compartido]);
        $this->assertSame([], Marcas::de('diagnosticos', [$general]));
        $this->assertSame([$ped->id], Marcas::de('diagnosticos', [$asma])[$asma]);
        $this->assertSame([$gin->id], Marcas::de('diagnosticos', [$deGine])[$deGine]);
        $this->assertSame([$ped->id], Marcas::de('medicamentos', [$usado])[$usado]);
        $this->assertSame(1, DB::table('diagnosticos')->where('reg_medico', 'copia-ped')->count());

        // ASMA no se duplica y NUEVO entra con la marca de pediatría.
        $this->assertSame(1, DB::table('diagnosticos')->whereNull('reg_medico')->where('descripcion', 'ASMA')->count());
        $nuevo = (int) DB::table('diagnosticos')->whereNull('reg_medico')->where('descripcion', 'NUEVO')->value('id');
        $this->assertSame([$ped->id], Marcas::de('diagnosticos', [$nuevo])[$nuevo]);

        // Una especialidad sin carpeta no se puede reemplazar.
        $this->expectException(InvalidArgumentException::class);
        app(SembrarCatalogosIniciales::class)->ejecutar($carpeta, false, ['cardiologia']);
    }

    public function test_dietas_y_antecedentes_se_siembran_y_se_copian_con_su_tipo(): void
    {
        $ped = $this->esp('pediatria');
        $carpeta = $this->archivos(['pediatria' => [
            'dietas' => [['codigo_origen' => '05', 'dietas' => 'antiestreñimiento', 'descripcion' => "ALIMENTOS PERMITIDOS:\n Casabe", 'calorias' => '1']],
            'antecedentes' => [
                ['codigo_origen' => '0012', 'descripcion' => 'asma bronquial', 'codtipo' => '02'],
                ['codigo_origen' => '0013', 'descripcion' => 'ASMA BRONQUIAL', 'codtipo' => '01'],
                ['codigo_origen' => '0014', 'descripcion' => 'SIN TIPO', 'codtipo' => '09'],
            ],
        ]]);

        $this->artisan('catalogos:sembrar-iniciales', ['--carpeta' => $carpeta])->assertExitCode(0);

        // El mismo nombre en dos tipos son dos antecedentes; el de un tipo que no existe no entra.
        $this->assertEqualsCanonicalizing(['01', '02'], DB::table('antecedentes')->whereNull('reg_medico')->pluck('codtipo')->all());
        $this->assertSame(['ASMA BRONQUIAL'], DB::table('antecedentes')->whereNull('reg_medico')->distinct()->pluck('descripcion')->all());
        $this->assertSame('ANTIESTREÑIMIENTO', DB::table('dietas')->whereNull('reg_medico')->value('dietas'));

        // El pediatra los recibe con el tipo tal cual y sus propios códigos.
        [, $medico] = $this->medico([$ped]);
        $copia = app(CopiarCatalogoInicial::class);
        $this->assertSame(2, $copia->ejecutar('antecedentes', $medico->reg_medico, [$medico->reg_medico], [$ped->id])['copiadas']);
        $this->assertSame(1, $copia->ejecutar('dietas', $medico->reg_medico, [$medico->reg_medico], [$ped->id])['copiadas']);
        $this->assertSame(['01', '02'], $this->propias('antecedentes', $medico, 'codtipo'));
        $this->assertSame(['0001', '0002'], $this->propias('antecedentes', $medico, 'codantecedente'));
        $this->assertSame(['01'], $this->propias('dietas', $medico, 'cod_dieta'));

        // El administrador elige el tipo de una lista cerrada.
        $guardar = app(GuardarFilaDeCatalogoInicial::class);
        $guardar->ejecutar('antecedentes', ['descripcion' => 'Varicela', 'codtipo' => '05']);
        $this->assertSame('05', trim((string) DB::table('antecedentes')->whereNull('reg_medico')->where('descripcion', 'VARICELA')->value('codtipo')));
        $this->expectException(InvalidArgumentException::class);
        $guardar->ejecutar('antecedentes', ['descripcion' => 'Otro', 'codtipo' => '99']);
    }

    // ------------------------------------------------------------------------------------------ La copia automática

    public function test_al_asignar_una_especialidad_el_medico_nuevo_recibe_su_catalogo(): void
    {
        $ped = $this->esp('pediatria');
        $car = $this->esp('cardiologia');
        $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'FIEBRE']);
        $this->ini('diagnosticos', ['codediagnostico' => '0002', 'descripcion' => 'ASMA'], [$ped]);
        $this->ini('diagnosticos', ['codediagnostico' => '0003', 'descripcion' => 'ARRITMIA'], [$car]);
        $this->ini('vademecum', ['codemedicina' => '0001', 'nombrecomercial' => 'ATAMEL'], [$ped]);
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'TOS']);
        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas')->insert(['reg_medico' => null, 'codi_vacuna' => 'V1', 'vacuna' => 'BCG', 'created_at' => now(), 'updated_at' => now()]);
        [, $medico] = $this->medico();
        // Ya tenía motivos de consulta propios: ese catálogo no se toca.
        DB::table('motivos_consulta')->insert(['reg_medico' => $medico->reg_medico, 'codemotivo' => '0001', 'descripcion' => 'PROPIO', 'created_at' => now(), 'updated_at' => now()]);

        $cambios = app(CuentaService::class)->asignarEspecialidades($medico, [$ped->id]);

        $this->assertSame(['ASMA', 'FIEBRE'], $this->propias('diagnosticos', $medico, 'descripcion'));
        $this->assertSame(['ATAMEL'], $this->propias('vademecum', $medico, 'nombrecomercial'));
        $this->assertSame(['PROPIO'], $this->propias('motivos_consulta', $medico, 'descripcion'));
        $this->assertSame(['BCG'], $this->propias('vacunas', $medico, 'vacuna'));
        $this->assertSame(4, $cambios['catalogoInicial']['filas']);

        // Sumar cardiología después no le agrega nada: sus catálogos ya no están vacíos (lo nuevo lo carga él con el botón).
        $otra = app(CuentaService::class)->asignarEspecialidades($medico->fresh(), [$ped->id, $car->id]);
        $this->assertSame(0, $otra['catalogoInicial']['filas']);
        $this->assertSame(['ASMA', 'FIEBRE'], $this->propias('diagnosticos', $medico, 'descripcion'));

        // Quitar sin asignar no corre la copia.
        $this->assertNull(app(CuentaService::class)->asignarEspecialidades($medico->fresh(), [$ped->id])['catalogoInicial']);
    }

    public function test_no_copia_al_medico_del_escritorio_ni_al_centro(): void
    {
        $ped = $this->esp('pediatria');
        $this->ini('diagnosticos', ['codediagnostico' => '0001', 'descripcion' => 'FIEBRE']);

        [, $escritorio] = $this->medico();
        DB::table('sync_cargas')->insert(['reg_medico' => $escritorio->reg_medico, 'medico_id' => $escritorio->id, 'estado' => 'completa', 'created_at' => now(), 'updated_at' => now()]);
        $cambios = app(CuentaService::class)->asignarEspecialidades($escritorio, [$ped->id]);
        $this->assertSame('trabaja con el escritorio', $cambios['catalogoInicial']['saltado']);
        $this->assertSame([], $this->propias('diagnosticos', $escritorio, 'descripcion'));

        [, $centro] = $this->medico();
        $centro->forceFill(['es_centro' => true])->save();
        $this->assertSame('es un centro médico', app(CargarCatalogosInicialesAlMedico::class)->ejecutar($centro->fresh())['saltado']);
        $this->assertSame([], $this->propias('diagnosticos', $centro, 'descripcion'));
    }
}
