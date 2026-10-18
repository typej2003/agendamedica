<?php

namespace Tests\Feature;

use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\CrearCita;
use App\Actions\Agenda\EliminarCita;
use App\Actions\Catalogos\CrearMotivoDeCita;
use App\Actions\Configuracion\GuardarPlantillasDeMensaje;
use App\Actions\Consulta\AgregarMotivoALaConsulta;
use App\Actions\Consulta\GuardarEvolucionDeLaConsulta;
use App\Actions\Consulta\GuardarRecipe;
use App\Actions\Consulta\QuitarMotivoDeLaConsulta;
use App\Models\Cola;
use App\Models\MotivoConsulta;
use App\Models\SyncChange;
use App\Sync\RegistroDeCambios;
use App\Sync\UsuarioDelRastro;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **El rastro lleva el usuario** (WEB-8.5, la bitácora): toda escritura de la web con `source = web` anota `sync_changes.user_id`,
 * en un único punto (el modelo `SyncChange`), y el móvil, el escritorio y la consola siguen escribiendo `NULL`.
 *
 * El **test guardia** ejecuta una Action de cada familia con una sesión web y exige que TODAS las filas que dejaron lleven el
 * usuario: una Action futura que deje su rastro por otro camino (un `INSERT` directo que se salte el modelo) hace fallar el segundo
 * caso, que busca esos caminos en el código.
 */
class RastroConUsuarioTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------- Las Actions

    public function test_toda_escritura_de_la_web_deja_el_usuario_que_la_hizo(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $motivo = MotivoConsulta::create(['reg_medico' => $medico->reg_medico, 'codemotivo' => '9001', 'descripcion' => 'CONTROL']);
        $antes = (int) SyncChange::max('id');

        // La secretaria agenda, confirma, cobra y elimina...
        $this->actingAs($secretaria);
        $cita = app(CrearCita::class)->ejecutar($medico->reg_medico, [
            'numhistoria' => 1001, 'fecha' => '2026-10-07', 'hora_ini' => '10:00:00', 'atendido' => 0, 'estado' => 0,
        ], Carbon::now());
        app(ConfirmarCita::class)->ejecutar($cita, Cola::ESTADOS[0], Carbon::now()->addSecond());
        app(CobrarCita::class)->fijarMonto($cita->fresh(), 40.0, Carbon::now()->addSeconds(2));
        $otra = Cola::create(['reg_medico' => $medico->reg_medico, 'fecha' => '2026-10-08', 'numhistoria' => 1001, 'hora_ini' => '11:00:00', 'atendido' => 0, 'estado' => 0]);
        app(EliminarCita::class)->ejecutar($otra);   // el rastro lo deja Cola::booted() con source = api
        $hastaSecretaria = (int) SyncChange::max('id');

        $delaSecretaria = SyncChange::where('id', '>', $antes)->where('id', '<=', $hastaSecretaria)->get();
        $this->assertGreaterThanOrEqual(4, $delaSecretaria->count());
        $this->assertSame([], $delaSecretaria->whereNull('user_id')->pluck('table_name')->all(), 'Todo lo que escribió la secretaria lleva su usuario.');
        $this->assertSame([$secretaria->id], $delaSecretaria->pluck('user_id')->unique()->values()->all());
        $this->assertContains('api', $delaSecretaria->pluck('source')->all(), 'Se probó también el rastro que dejan los modelos al borrarse.');

        // ... y el médico guarda la consulta, el récipe, un motivo, el catálogo y su configuración.
        $this->actingAs($user);
        app(GuardarEvolucionDeLaConsulta::class)->ejecutar($consulta, 'Evolución', Carbon::now()->addMinute(), 'web');
        app(GuardarRecipe::class)->ejecutar($consulta, [['codemedicina' => '0001', 'cantidad' => 1, 'indicaciones' => 'x']], '2026-10-07', null, Carbon::now(), 'web');
        [$fila] = app(AgregarMotivoALaConsulta::class)->ejecutar($consulta, $motivo, 'web');   // SyncChange::create a mano
        app(QuitarMotivoDeLaConsulta::class)->ejecutar($fila->id, null, 'web');
        app(CrearMotivoDeCita::class)->ejecutar($medico, $medico->reg_medico, ['razon' => 'Guardia', 'precio' => '10'], Carbon::now(), 'web');
        app(GuardarPlantillasDeMensaje::class)->ejecutar($medico, ['plantilla_cita' => 'Hola {paciente}, te esperamos'], Carbon::now(), 'web');

        $delMedico = SyncChange::where('id', '>', $hastaSecretaria)->get();
        $tablas = $delMedico->pluck('table_name')->unique()->all();
        foreach (['consultas', 'recipes', 'motivo_consulta_paciente', 'motivo_cita', 'evolucion'] as $tabla) {
            $this->assertContains($tabla, $tablas, "La prueba tiene que haber escrito en {$tabla}.");
        }
        $this->assertSame([], $delMedico->whereNull('user_id')->pluck('table_name')->all(), 'Todo lo que escribió el médico lleva su usuario.');
        $this->assertSame([$user->id], $delMedico->pluck('user_id')->unique()->values()->all());
    }

    public function test_ningun_codigo_escribe_en_sync_changes_saltandose_el_modelo(): void
    {
        $raiz = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
        $encontrados = [];

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $codigo = file_get_contents($archivo->getPathname());
            // Las escrituras que se saltan el modelo: `DB::table('sync_changes')->insert(...)` y parientes, o `SyncChange::insert/upsert`.
            if (preg_match('/table\([\'"]sync_changes[\'"]\)\s*->\s*(insert|insertGetId|insertOrIgnore|insertUsing|upsert|updateOrInsert)|SyncChange::(insert|upsert|insertOrIgnore)/', $codigo, $m) === 1) {
                $encontrados[] = $archivo->getPathname() . ' (' . $m[0] . ')';
            }
        }

        $this->assertSame([], $encontrados, 'Un INSERT directo a sync_changes no pasa por el modelo y no lleva el usuario de la bitácora.');
    }

    // ----------------------------------------------------------------------- Lo que NO lleva usuario

    public function test_lo_que_no_es_de_una_sesion_web_queda_sin_usuario(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $this->actingAs($user);
        $cuando = Carbon::now();

        // El móvil, el escritorio y la migración escriben con su origen aunque haya una sesión web en el proceso.
        foreach (['mobile', 'escritorio', 'migracion'] as $origen) {
            RegistroDeCambios::registrar('cola', 1, $medico->reg_medico, 'estado', 1, $cuando, $origen);
        }
        $this->assertSame(0, SyncChange::where('reg_medico', $medico->reg_medico)->whereNotNull('user_id')->count());

        // Una escritura web sin sesión (la consola, un job) tampoco inventa un usuario.
        Auth::guard('web')->logout();
        RegistroDeCambios::registrar('cola', 2, $medico->reg_medico, 'estado', 1, $cuando, 'web');
        $this->assertNull(SyncChange::where('record_id', 2)->where('reg_medico', $medico->reg_medico)->value('user_id'));
    }

    public function test_una_peticion_del_api_nunca_atribuye_la_escritura_a_una_sesion_web(): void
    {
        [$user] = $this->medicoConAcceso();
        $this->actingAs($user);

        $this->assertSame($user->id, UsuarioDelRastro::actual());

        app()->instance('request', Request::create('/api/app/sync-app-data', 'POST'));
        $this->assertNull(UsuarioDelRastro::actual());
    }

    public function test_el_usuario_puesto_a_mano_no_se_pisa(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $this->actingAs($user);

        $cambio = SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'cola', 'record_id' => 7, 'operation' => 'created',
            'occurred_at' => Carbon::now(), 'source' => 'web', 'user_id' => 424242,
        ]);

        $this->assertSame(424242, $cambio->fresh()->user_id);
    }

    // ------------------------------------------------------------------------ El JSON del móvil no cambia

    public function test_el_movil_no_ve_el_usuario_en_lo_que_baja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->pacienteYConsulta($medico, 1001, 1);
        $cita = Cola::create(['reg_medico' => $medico->reg_medico, 'fecha' => '2026-10-08', 'hora_ini' => '11:00:00', 'numhistoria' => 1001, 'atendido' => 0, 'estado' => 0]);
        $desde = Carbon::now()->subMinute()->toIso8601String();

        // La secretaria borra la cita desde la web: queda un `deleted` CON su usuario...
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.agenda.eliminar', $cita->id))->assertRedirect();
        $this->assertSame($secretaria->id, SyncChange::where('table_name', 'cola')->where('record_id', $cita->id)->where('operation', 'deleted')->value('user_id'));

        // ... y el móvil se entera de la baja con las tres claves de siempre, sin ninguna más.
        app('auth')->forgetGuards();
        Sanctum::actingAs($user, ['*'], 'api');
        $pull = $this->postJson('/api/app/sync-app-data', ['changes' => [], 'since' => $desde])->assertOk();

        $baja = collect($pull->json('eliminados'))->first(fn ($e) => $e['table_name'] === 'cola' && (int) $e['record_id'] === $cita->id);
        $this->assertNotNull($baja);
        $this->assertSame(['occurred_at', 'record_id', 'table_name'], collect($baja)->keys()->sort()->values()->all());
        $this->assertArrayNotHasKey('user_id', $baja, 'La baja no expone quién la hizo.');
    }

    public function test_la_columna_y_sus_indices_existen(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('sync_changes', 'user_id'));

        // Los índices de la bitácora, con la consulta de cada motor: SQLite no entiende `SHOW INDEX` y
        // MySQL/MariaDB no entienden `PRAGMA`.
        $conexion = DB::connection();

        $indices = $conexion->getDriverName() === 'sqlite'
            ? collect(DB::select("PRAGMA index_list('sync_changes')"))->pluck('name')->all()
            : collect($conexion->select('SHOW INDEX FROM sync_changes'))->pluck('Key_name')->unique()->values()->all();

        $this->assertContains('sync_changes_bitacora_idx', $indices);
        $this->assertContains('sync_changes_bitacora_usuario_idx', $indices);
    }
}
