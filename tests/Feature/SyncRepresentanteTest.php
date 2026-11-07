<?php

namespace Tests\Feature;

use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\Representante;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **La pediatría mínima en el bolsillo (2026-11-04): el representante y el alta del niño.**
 *
 * El cierre de la consulta pediátrica pide el **teléfono del representante** y no el del paciente, y el
 * paciente migrado de PediaReport trae el **nombre completo** en `nombres`, sin apellido ni cédula. Para que
 * el app pueda hacer lo mismo sin la computadora faltaban dos cosas en el sync, y esto es lo que se fija:
 *
 *  1. **El representante baja** (delta, volcado completo, volcado paginado y pedido puntual de una ficha),
 *     con el filtro por registro y por número de historia —el número es por médico— y sin la fila de otro.
 *  2. **El app lo crea y lo corrige**: la creación es por historia (una sola fila, la clave del legado) y la
 *     corrección va por columna con su last-write-wins y su rastro `source = mobile`.
 *  3. **El alta del niño no exige cédula ni apellido**: es la misma regla de `CrearPaciente` que usa la web.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncRepresentanteTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-rep-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Pediatra de prueba',
            'email' => 'pediatra-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Pediatra',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    /** Un niño migrado: nombre completo, sin apellido ni cédula, con su historia. */
    private function nino(Medico $medico, ?int $numHistoria = null): Paciente
    {
        $numHistoria = $numHistoria ?? random_int(900000000, 999999999);

        $paciente = Paciente::create([
            'nombres' => 'OUBIED ALHENAWI ALIA',
            'apellidos' => null,
            'cedula' => null,
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => (string) $numHistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numHistoria,
            'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);

        return $paciente;
    }

    private function representante(Medico $medico, int $numHistoria, array $datos = []): Representante
    {
        return Representante::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numHistoria,
            'nombre' => 'REPRESENTANTE DE PRUEBA',
            'codeparentesco' => 'M',
            'telefono' => '04141112222',
        ], $datos));
    }

    /** La familia del escritorio de pediatría: la fila de `familia_bebe` y el vínculo con el niño. */
    private function familia(Medico $medico, int $numHistoria, int $familia, array $telefonos = []): void
    {
        DB::table('familia_bebe')->insert(array_merge([
            'reg_medico' => $medico->reg_medico, 'familia' => $familia,
            'nom_madre' => 'MAMÁ DE PRUEBA', 'created_at' => now(), 'updated_at' => now(),
        ], $telefonos));
        DB::table('paciente_chamo')->insert([
            'reg_medico' => $medico->reg_medico, 'familia' => $familia, 'historia' => $numHistoria,
            'nombre' => 'OUBIED ALHENAWI ALIA', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    // ------------------------------------------------------------------------------------ La bajada

    public function test_el_representante_baja_con_el_delta(): void
    {
        $medico = $this->medico();
        $paciente = $this->nino($medico);
        $fila = $this->representante($medico, (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria'));

        $r = $this->sync(['since' => Carbon::now()->subMinute()->toIso8601String()]);

        $this->assertSame([$fila->id], array_column($r->json('representante'), 'id'));
        $this->assertSame('04141112222', $r->json('representante.0.telefono'));
        $this->assertSame('REPRESENTANTE DE PRUEBA', $r->json('representante.0.nombre'));
    }

    public function test_no_baja_el_representante_de_otro_medico_con_el_mismo_numero_de_historia(): void
    {
        $medico = $this->medico();
        $paciente = $this->nino($medico);
        $numero = (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria');
        $propio = $this->representante($medico, $numero);

        // El número es por médico: la fila del otro consultorio no puede llegar a este teléfono.
        Representante::create([
            'reg_medico' => 'otro-registro' . substr(uniqid(), -6),
            'numhistoria' => $numero,
            'nombre' => 'EL DE OTRO CONSULTORIO',
            'telefono' => '04149999999',
        ]);

        $r = $this->sync();

        $this->assertSame([$propio->id], array_column($r->json('representante'), 'id'));
        $this->assertNotContains('EL DE OTRO CONSULTORIO', array_column($r->json('representante'), 'nombre'));
    }

    public function test_el_volcado_completo_lo_trae_y_la_clave_existe_aunque_no_haya_representante(): void
    {
        $medico = $this->medico();

        // Sin ningún niño, la clave tiene que venir igual: el app recorre una lista fija de tablas.
        $this->assertArrayHasKey('representante', $this->sync()->json());

        $paciente = $this->nino($medico);
        $this->representante($medico, (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria'));

        $r = $this->sync();
        $this->assertCount(1, $r->json('representante'), 'baja en el volcado completo');
    }

    public function test_el_nino_y_su_familia_bajan_para_resolver_el_telefono_del_adulto(): void
    {
        $medico = $this->medico();
        $paciente = $this->nino($medico);
        $numero = (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria');
        $this->familia($medico, $numero, 55, ['celu_madre' => '04143334444']);

        // La familia de otro consultorio con el mismo número de familia no puede llegar.
        DB::table('familia_bebe')->insert([
            'reg_medico' => 'otro-registro' . substr(uniqid(), -6), 'familia' => 55,
            'celu_madre' => '04149999999', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('paciente_chamo')->insert([
            'reg_medico' => 'otro-registro' . substr(uniqid(), -6), 'familia' => 55, 'historia' => $numero,
            'nombre' => 'EL DE OTRO CONSULTORIO', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->sync();

        $this->assertCount(1, $r->json('paciente_chamo'));
        $this->assertCount(1, $r->json('familia_bebe'));
        $this->assertSame($numero, $r->json('paciente_chamo.0.historia'));
        $this->assertSame('04143334444', $r->json('familia_bebe.0.celu_madre'));
        $this->assertSame(55, $r->json('paciente_chamo.0.familia'));
    }

    public function test_el_pedido_de_una_ficha_fuera_de_la_ventana_trae_el_representante(): void
    {
        $medico = $this->medico();
        $paciente = $this->nino($medico);
        $numero = (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria');
        $this->representante($medico, $numero);

        // `volcado_paciente` va por el **número de historia**, no por el id de la ficha.
        $r = $this->sync(['volcado_paciente' => $numero]);

        $this->assertCount(1, $r->json('representante'));
        $this->assertSame('04141112222', $r->json('representante.0.telefono'));
    }

    public function test_el_volcado_paginado_lo_trae_para_el_paciente_de_la_ventana(): void
    {
        $medico = $this->medico();
        $paciente = $this->nino($medico);
        $numero = (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria');
        $this->representante($medico, $numero);

        // La ventana del volcado se mide por la cita: sin una cita de estos días, lo clínico no viaja.
        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDay()->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $numero,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vistos = [];
        $cursor = 'inicio';
        $paginas = 0;
        while ($cursor !== null && $paginas < 200) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 1])->json();
            // La clave tiene que venir siempre, aunque en este tramo no toque.
            $this->assertArrayHasKey('representante', $cuerpo);
            $vistos = array_merge($vistos, $cuerpo['representante']);
            $cursor = $cuerpo['volcado_siguiente'];
            $paginas++;
        }

        $this->assertLessThan(200, $paginas, 'el volcado tiene que terminar');
        $this->assertCount(1, $vistos, 'el representante del paciente de la ventana');
        $this->assertSame('04141112222', $vistos[0]['telefono']);
    }

    // ------------------------------------------------------------------------------------ La subida

    public function test_el_app_crea_el_representante_por_historia_y_no_duplica(): void
    {
        $medico = $this->medico();
        $paciente = $this->nino($medico);
        $numero = (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria');

        $r = $this->sync(['changes' => [[
            'table' => 'representante', 'operation' => 'created', 'temp_id' => 7001,
            'columns' => [
                'numhistoria' => $numero, 'nombre' => 'LA MAMÁ', 'codeparentesco' => 'M',
                'telefono' => '04241112233',
            ],
        ]]]);

        $this->assertSame([], $r->json('rechazados'));
        $creado = collect($r->json('creados'))->firstWhere('table', 'representante');
        $this->assertNotNull($creado, 'el app tiene que recibir el id real de la fila');

        $this->assertSame(1, Representante::where('numhistoria', $numero)->count(), 'una sola fila por historia');
        $this->assertSame('04241112233', Representante::where('numhistoria', $numero)->value('telefono'));
        $this->assertSame(1, SyncChange::where('table_name', 'representante')
            ->where('record_id', $creado['id'])->where('operation', 'created')->where('source', 'mobile')->count());

        // Volver a guardar (otro dispositivo, u otro `temp_id`) completa la MISMA fila: es un upsert por
        // historia, no un alta que duplique.
        $this->sync(['changes' => [[
            'table' => 'representante', 'operation' => 'created', 'temp_id' => 7002,
            'columns' => ['numhistoria' => $numero, 'nombre' => 'LA MAMÁ', 'telefono' => '04241112244'],
        ]]]);

        $this->assertSame(1, Representante::where('numhistoria', $numero)->count());
        $this->assertSame('04241112244', Representante::where('numhistoria', $numero)->value('telefono'));
    }

    public function test_el_app_no_puede_crear_el_representante_de_una_historia_ajena(): void
    {
        $this->medico();
        $otro = $this->medico(false);
        $pacienteAjeno = $this->nino($otro);
        $numeroAjeno = (int) MedicoPaciente::where('paciente_id', $pacienteAjeno->id)->value('numhistoria');

        $r = $this->sync(['changes' => [[
            'table' => 'representante', 'operation' => 'created', 'temp_id' => 7003,
            'columns' => ['numhistoria' => $numeroAjeno, 'nombre' => 'NO DEBE ENTRAR', 'telefono' => '04140000000'],
        ]]]);

        $this->assertCount(1, $r->json('rechazados'));
        $this->assertSame(0, Representante::where('numhistoria', $numeroAjeno)->count());
    }

    public function test_el_app_corrige_el_telefono_por_columna_y_deja_rastro(): void
    {
        $medico = $this->medico();
        $paciente = $this->nino($medico);
        $fila = $this->representante($medico, (int) MedicoPaciente::where('paciente_id', $paciente->id)->value('numhistoria'));

        $this->sync(['changes' => [[
            'table' => 'representante', 'operation' => 'updated', 'record_id' => $fila->id,
            'column' => 'telefono', 'value' => '04249998877',
            'occurred_at' => Carbon::now()->toIso8601String(),
        ]]]);

        $this->assertSame('04249998877', $fila->fresh()->telefono);
        $este = SyncChange::where('table_name', 'representante')->where('record_id', $fila->id)
            ->where('column_name', 'telefono')->where('source', 'mobile')->first();
        $this->assertNotNull($este);
        $this->assertSame('04249998877', $este->value);
    }

    public function test_un_representante_ajeno_no_se_edita(): void
    {
        $this->medico();
        $otro = $this->medico(false);
        $pacienteAjeno = $this->nino($otro);
        $ajeno = $this->representante($otro, (int) MedicoPaciente::where('paciente_id', $pacienteAjeno->id)->value('numhistoria'));

        $this->sync(['changes' => [[
            'table' => 'representante', 'operation' => 'updated', 'record_id' => $ajeno->id,
            'column' => 'telefono', 'value' => '04140000001',
            'occurred_at' => Carbon::now()->toIso8601String(),
        ]]]);

        $this->assertSame('04141112222', $ajeno->fresh()->telefono, 'la fila del otro consultorio no se toca');
        $this->assertSame(0, SyncChange::where('table_name', 'representante')->where('record_id', $ajeno->id)->count());
    }

    // ------------------------------------------------------------------------- El alta del niño

    public function test_un_paciente_pediatrico_se_crea_sin_cedula_ni_apellido(): void
    {
        $this->medico();

        $r = $this->sync(['changes' => [[
            'table' => 'pacientes', 'operation' => 'created', 'temp_id' => 7101,
            'columns' => [
                'nombres' => 'OUBIED ALHENAWI ALIA',
                'fnacimiento' => '2024-10-09',
                'sexo' => 'F',
            ],
        ]]]);

        $this->assertSame([], $r->json('rechazados'));
        $creado = collect($r->json('creados'))->firstWhere('table', 'pacientes');
        $this->assertNotNull($creado, 'el alta tiene que devolver el id real');

        $paciente = Paciente::find($creado['id']);
        $this->assertSame('OUBIED ALHENAWI ALIA', $paciente->nombres);
        $this->assertNull($paciente->apellidos);
        $this->assertNull($paciente->cedula);
        $this->assertSame(1, MedicoPaciente::where('paciente_id', $paciente->id)->count(), 'queda vinculado');
    }

    public function test_un_paciente_sin_nombre_sigue_rechazandose(): void
    {
        $this->medico();

        $r = $this->sync(['changes' => [[
            'table' => 'pacientes', 'operation' => 'created', 'temp_id' => 7102,
            'columns' => ['apellidos' => 'SIN NOMBRE', 'sexo' => 'F'],
        ]]]);

        $this->assertCount(1, $r->json('rechazados'));
        $this->assertStringContainsString('nombres', (string) $r->json('rechazados.0.motivo'));
    }
}
