<?php

namespace Tests\Feature;

use App\Actions\Catalogos\CargarCatalogosInicialesAlMedico;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales as Registro;
use App\Clinica\Catalogos\Iniciales\EspecialidadesDelCatalogoInicial as Marcas;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Los motivos de consulta y los antecedentes de ginecología** (2026-10-28, pedido de la doctora Olivia): la base real de ginecología no trajo esos dos catálogos, así que
 * se sacaron de la base de ejemplo (`database/catalogos-iniciales/ginecologia-y-obstetricia/`) y ahora los recibe la ginecóloga nueva al asignarle la especialidad y los
 * médicos que ya tenían cuenta con `catalogos:cargar-a-medicos`. Un pediatra no los recibe (están marcados sólo para ginecología), y el médico del escritorio y el centro
 * médico siguen saltándose.
 */
class CatalogoInicialGinecologiaTest extends TestCase
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
        $reg = 'cat-gin-' . substr(uniqid(), -6);
        $user = User::create(['name' => 'Usuario', 'email' => $reg . '@example.com', 'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false]);
        $user->assignRole('Medico');
        $medico = Medico::create(['user_id' => $user->id, 'name' => 'Dra', 'lastname' => 'Gineco', 'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg]);
        $medico->specialties()->attach(array_map(fn (Specialty $e) => $e->id, $especialidades));
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico];
    }

    private function propias(string $tabla, Medico $medico, string $columna): array
    {
        return DB::table($tabla)->where('reg_medico', $medico->reg_medico)->orderBy($columna)->pluck($columna)->map(fn ($v) => trim((string) $v))->all();
    }

    private function propio(string $tabla, Medico $medico, array $fila): void
    {
        DB::table($tabla)->insert($fila + ['reg_medico' => $medico->reg_medico, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** El catálogo inicial mínimo de ginecología y pediatría, con uno general para ver que también viaja. */
    private function catalogosIniciales(Specialty $gin, Specialty $ped): void
    {
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL PRENATAL'], [$gin]);
        $this->ini('motivos_consulta', ['codemotivo' => '0002', 'descripcion' => 'FIEBRE'], [$ped]);
        $this->ini('motivos_consulta', ['codemotivo' => '0003', 'descripcion' => 'CONSULTA GENERAL']);
        $this->ini('antecedentes', ['codantecedente' => '0001', 'descripcion' => 'DISPAREUNIA', 'codtipo' => '06'], [$gin]);
        $this->ini('antecedentes', ['codantecedente' => '0002', 'descripcion' => 'ASMA', 'codtipo' => '01'], [$ped]);
    }

    // ------------------------------------------------------------------------------------------ El médico nuevo

    public function test_una_ginecologa_nueva_recibe_motivos_de_consulta_y_antecedentes(): void
    {
        $gin = $this->esp('ginecologia-y-obstetricia');
        $ped = $this->esp('pediatria');
        $this->catalogosIniciales($gin, $ped);
        [, $medico] = $this->medico();

        $cambios = app(CuentaService::class)->asignarEspecialidades($medico, [$gin->id]);

        $this->assertSame(['CONSULTA GENERAL', 'CONTROL PRENATAL'], $this->propias('motivos_consulta', $medico, 'descripcion'));
        $this->assertSame(['DISPAREUNIA'], $this->propias('antecedentes', $medico, 'descripcion'));
        $this->assertSame(['06'], $this->propias('antecedentes', $medico, 'codtipo'));
        $this->assertSame(3, $cambios['catalogoInicial']['filas']);
    }

    public function test_un_pediatra_no_recibe_los_catalogos_marcados_solo_para_ginecologia(): void
    {
        $gin = $this->esp('ginecologia-y-obstetricia');
        $ped = $this->esp('pediatria');
        $this->catalogosIniciales($gin, $ped);
        [, $medico] = $this->medico();

        app(CuentaService::class)->asignarEspecialidades($medico, [$ped->id]);

        $this->assertSame(['CONSULTA GENERAL', 'FIEBRE'], $this->propias('motivos_consulta', $medico, 'descripcion'));
        $this->assertSame(['ASMA'], $this->propias('antecedentes', $medico, 'descripcion'));
    }

    public function test_el_medico_del_escritorio_y_el_centro_no_reciben_esos_catalogos(): void
    {
        $gin = $this->esp('ginecologia-y-obstetricia');
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL PRENATAL'], [$gin]);
        $this->ini('antecedentes', ['codantecedente' => '0001', 'descripcion' => 'DISPAREUNIA', 'codtipo' => '06'], [$gin]);

        // El del escritorio: tiene una carga del puente.
        [, $escritorio] = $this->medico();
        DB::table('sync_cargas')->insert(['reg_medico' => $escritorio->reg_medico, 'medico_id' => $escritorio->id, 'estado' => 'completa', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('trabaja con el escritorio', app(CuentaService::class)->asignarEspecialidades($escritorio, [$gin->id])['catalogoInicial']['saltado']);
        $this->assertSame([], $this->propias('motivos_consulta', $escritorio, 'descripcion'));
        $this->assertSame([], $this->propias('antecedentes', $escritorio, 'descripcion'));

        // El del escritorio por credencial del puente (el otro camino del salto).
        [, $puente] = $this->medico();
        DB::table('sync_credentials')->insert([
            'reg_medico' => $puente->reg_medico, 'machine_label' => 'CONSULTORIO-1', 'token_hash' => hash('sha256', uniqid()),
            'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('trabaja con el escritorio', app(CargarCatalogosInicialesAlMedico::class)->ejecutar($puente)['saltado']);

        // El centro médico.
        [, $centro] = $this->medico();
        $centro->forceFill(['es_centro' => true])->save();
        $this->assertSame('es un centro médico', app(CuentaService::class)->asignarEspecialidades($centro->fresh(), [$gin->id])['catalogoInicial']['saltado']);
        $this->assertSame([], $this->propias('motivos_consulta', $centro, 'descripcion'));
        $this->assertSame([], $this->propias('antecedentes', $centro, 'descripcion'));
    }

    // ------------------------------------------------------------------------------------------ El relleno de los que ya tienen cuenta

    public function test_el_comando_llena_el_catalogo_vacio_y_no_toca_el_que_ya_tiene_filas(): void
    {
        $gin = $this->esp('ginecologia-y-obstetricia');
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL PRENATAL'], [$gin]);
        $this->ini('antecedentes', ['codantecedente' => '0001', 'descripcion' => 'DISPAREUNIA', 'codtipo' => '06'], [$gin]);
        [, $medico] = $this->medico([$gin]);

        // Ya había cargado un motivo propio: ese catálogo no se toca, aunque tenga una sola fila.
        $this->propio('motivos_consulta', $medico, ['codemotivo' => '0001', 'descripcion' => 'PROPIO']);

        $this->artisan('catalogos:cargar-a-medicos')->assertExitCode(0);

        $this->assertSame(['PROPIO'], $this->propias('motivos_consulta', $medico, 'descripcion'));
        $this->assertSame(['DISPAREUNIA'], $this->propias('antecedentes', $medico, 'descripcion'));

        // Idempotente: la segunda corrida no copia nada.
        $antes = DB::table('antecedentes')->where('reg_medico', $medico->reg_medico)->count();
        $this->artisan('catalogos:cargar-a-medicos')
            ->expectsOutputToContain('Se copiaron 0 fila(s)')
            ->assertExitCode(0);
        $this->assertSame($antes, DB::table('antecedentes')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_comando_saltea_al_medico_del_escritorio_y_al_centro(): void
    {
        $gin = $this->esp('ginecologia-y-obstetricia');
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL PRENATAL'], [$gin]);
        $this->ini('antecedentes', ['codantecedente' => '0001', 'descripcion' => 'DISPAREUNIA', 'codtipo' => '06'], [$gin]);

        [, $escritorio] = $this->medico([$gin]);
        DB::table('sync_cargas')->insert(['reg_medico' => $escritorio->reg_medico, 'medico_id' => $escritorio->id, 'estado' => 'completa', 'created_at' => now(), 'updated_at' => now()]);

        [, $centro] = $this->medico([$gin]);
        $centro->forceFill(['es_centro' => true])->save();

        $this->artisan('catalogos:cargar-a-medicos')
            ->expectsOutputToContain('saltado: trabaja con el escritorio')
            ->assertExitCode(0);

        $this->assertSame([], $this->propias('motivos_consulta', $escritorio, 'descripcion'));
        $this->assertSame([], $this->propias('antecedentes', $escritorio, 'descripcion'));
        // Al centro ni se lo recorre: la consulta de médicos lo excluye.
        $this->assertSame([], $this->propias('motivos_consulta', $centro, 'descripcion'));
        $this->assertSame([], $this->propias('antecedentes', $centro, 'descripcion'));
    }

    public function test_el_comando_simula_sin_escribir(): void
    {
        $gin = $this->esp('ginecologia-y-obstetricia');
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL PRENATAL'], [$gin]);
        $this->ini('antecedentes', ['codantecedente' => '0001', 'descripcion' => 'DISPAREUNIA', 'codtipo' => '06'], [$gin]);
        [, $medico] = $this->medico([$gin]);

        $this->artisan('catalogos:cargar-a-medicos', ['--simular' => true])
            ->expectsOutputToContain('--simular: no se escribió nada.')
            ->assertExitCode(0);

        $this->assertSame([], $this->propias('motivos_consulta', $medico, 'descripcion'));
        $this->assertSame([], $this->propias('antecedentes', $medico, 'descripcion'));

        $this->artisan('catalogos:cargar-a-medicos')->assertExitCode(0);
        $this->assertSame(['CONTROL PRENATAL'], $this->propias('motivos_consulta', $medico, 'descripcion'));
        $this->assertSame(['DISPAREUNIA'], $this->propias('antecedentes', $medico, 'descripcion'));
    }

    public function test_el_comando_copia_solo_los_catalogos_pedidos(): void
    {
        $gin = $this->esp('ginecologia-y-obstetricia');
        $this->ini('motivos_consulta', ['codemotivo' => '0001', 'descripcion' => 'CONTROL PRENATAL'], [$gin]);
        $this->ini('antecedentes', ['codantecedente' => '0001', 'descripcion' => 'DISPAREUNIA', 'codtipo' => '06'], [$gin]);
        [, $medico] = $this->medico([$gin]);

        $this->artisan('catalogos:cargar-a-medicos', ['--catalogo' => ['motivos-consulta']])->assertExitCode(0);

        $this->assertSame(['CONTROL PRENATAL'], $this->propias('motivos_consulta', $medico, 'descripcion'));
        $this->assertSame([], $this->propias('antecedentes', $medico, 'descripcion'));
    }

    public function test_el_comando_rechaza_un_catalogo_sin_version_inicial(): void
    {
        $this->artisan('catalogos:cargar-a-medicos', ['--catalogo' => ['vacunas']])
            ->expectsOutputToContain('no es un catálogo con versión inicial')
            ->assertExitCode(1);
    }
}
