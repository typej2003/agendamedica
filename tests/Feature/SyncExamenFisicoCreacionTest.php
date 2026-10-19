<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ExamenFisico;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A3 de PLAN-MOVIL.md: crear la evaluación física desde el teléfono.**
 *
 * Cargar la evaluación física es lo que faltaba para atender una consulta de principio a fin sin la
 * computadora: el teléfono manda una creación de `examen_fisico_nuevo` con las columnas que el médico
 * cargó y el servidor resuelve a qué fila del legado van. Lo que se fija acá:
 *
 *  1. Que la fila se cree con **la clave del legado** —`historia` = número de historia y `consulta` =
 *     `nroconsulta`, **no** el id de `consultas`— y que el teléfono reciba el id real para su `remote_id`.
 *  2. Que una consulta creada en el **mismo lote** (`consulta_temp_id`) se resuelva al número real.
 *  3. Que el reintento con el mismo `temp_id` **no duplique** la fila.
 *  4. Que una consulta de **otro** médico se rechace sin escribir nada ni trabar el resto del lote.
 *  5. Que lo que no está en la lista blanca (`total_f`, los resúmenes del escritorio) se descarte.
 *  6. Que el rastro quede `source = mobile`, con el `reg_medico` de la consulta.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncExamenFisicoCreacionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-examen-c' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
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

    /** Un paciente del médico con su historia y una consulta. El número se elige libre (es único). */
    private function consulta(Medico $medico): Consulta
    {
        $numhistoria = random_int(900000000, 999999999);

        $paciente = Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => 1,
        ]);
    }

    private function sync(array $changes)
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => $changes])->assertOk();
    }

    private function creado($response, string $tabla, int $tempId): ?array
    {
        return collect($response->json('creados'))
            ->first(fn ($c) => $c['table'] === $tabla && $c['temp_id'] === $tempId);
    }

    /** La fila que la creación dejó, buscada por la clave del legado y no por el id de la consulta. */
    private function fila(Medico $medico, Consulta $consulta): ?ExamenFisico
    {
        return ExamenFisico::where('reg_medico', $medico->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->first();
    }

    public function test_crear_la_evaluacion_de_una_consulta_propia_deja_la_fila_con_la_clave_del_legado(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'examen_fisico_nuevo',
            'operation' => 'created',
            'temp_id' => -40,
            'consulta_id' => $consulta->id,
            'columns' => [
                'tension' => '120/080',
                'general' => 'BUEN ESTADO GENERAL',
                'cardiopulmonar' => 'RUIDOS RESPIRATORIOS NORMALES',
            ],
        ]]);

        $fila = $this->fila($medico, $consulta);
        $this->assertNotNull($fila, 'la evaluación física se creó');
        $this->assertSame((int) $consulta->numhistoria, $fila->historia, '`historia` es el número de historia');
        $this->assertSame((int) $consulta->nroconsulta, $fila->consulta, '`consulta` es el NÚMERO de la consulta');
        $this->assertNotSame($consulta->id, $fila->consulta, 'no es el id de `consultas` (trampa del §7.4)');
        $this->assertSame('120/080', $fila->tension);
        $this->assertSame('BUEN ESTADO GENERAL', $fila->general);
        $this->assertSame('RUIDOS RESPIRATORIOS NORMALES', $fila->cardiopulmonar);
        $this->assertSame($medico->reg_medico, $fila->reg_medico);

        // Lo que el teléfono guarda como `remote_id`.
        $creado = $this->creado($response, 'examen_fisico_nuevo', -40);
        $this->assertNotNull($creado, 'la creación contesta con su id');
        $this->assertSame($fila->id, $creado['id']);
    }

    public function test_una_consulta_creada_en_el_mismo_lote_resuelve_al_numero_real(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // Desordenados a propósito, como los manda un teléfono sin conexión: el servidor los reordena.
        $response = $this->sync([
            [
                'table' => 'examen_fisico_nuevo',
                'operation' => 'created',
                'temp_id' => -41,
                'consulta_temp_id' => -21,
                'columns' => ['general' => 'PACIENTE EN BUEN ESTADO'],
            ],
            [
                'table' => 'consultas',
                'operation' => 'created',
                'temp_id' => -21,
                'numhistoria' => $consulta->numhistoria,
                'fecha' => now()->toDateString(),
            ],
        ]);

        $creada = $this->creado($response, 'consultas', -21);
        $this->assertNotNull($creada, 'la consulta del lote se creó primero');
        $this->assertSame([], $response->json('rechazados'));

        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $creada['nroconsulta'])
            ->first();

        $this->assertNotNull($fila, 'la evaluación cuelga de la consulta del mismo lote');
        $this->assertSame('PACIENTE EN BUEN ESTADO', $fila->general);
        // La consulta del lote es la 2 de esa historia: la fila tiene que colgar de ese número y no del 1.
        $this->assertSame(2, $fila->consulta);
        $this->assertSame($fila->id, $this->creado($response, 'examen_fisico_nuevo', -41)['id']);
    }

    public function test_reintentar_la_misma_creacion_no_duplica_la_fila(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $cambio = [[
            'table' => 'examen_fisico_nuevo',
            'operation' => 'created',
            'temp_id' => -42,
            'consulta_id' => $consulta->id,
            'columns' => ['tension' => '110/070', 'abdomen' => 'BLANDO, DEPRESIBLE'],
        ]];

        $primera = $this->sync($cambio);
        $segunda = $this->sync($cambio);

        $this->assertSame(
            $this->creado($primera, 'examen_fisico_nuevo', -42),
            $this->creado($segunda, 'examen_fisico_nuevo', -42),
            'el reintento contesta lo mismo',
        );
        $this->assertSame(1, ExamenFisico::where('reg_medico', $medico->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->count(), 'la fila no se duplica');
        $this->assertSame('110/070', $this->fila($medico, $consulta)->tension);
    }

    public function test_una_consulta_de_otro_medico_se_rechaza_y_no_escribe_nada(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajena = $this->consulta($otro);

        $this->medico();

        $response = $this->sync([[
            'table' => 'examen_fisico_nuevo',
            'operation' => 'created',
            'temp_id' => -43,
            'consulta_id' => $ajena->id,
            'columns' => ['general' => 'NO DEBERIA ENTRAR'],
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame('examen_fisico_nuevo', $response->json('rechazados.0.table'));
        $this->assertSame(-43, $response->json('rechazados.0.temp_id'));
        $this->assertSame(
            'La evaluación física no corresponde a una consulta de este médico.',
            $response->json('rechazados.0.motivo'),
        );

        $this->assertSame(0, ExamenFisico::where('historia', (int) $ajena->numhistoria)->count(),
            'no se escribió ninguna fila con la clave de la consulta ajena');
        $this->assertNull($this->fila($otro, $ajena), 'ni en la consulta ajena');
        $this->assertSame(0, SyncChange::where('table_name', 'examen_fisico_nuevo')
            ->where('client_temp_id', -43)
            ->count(), 'ni rastro de la creación rechazada');
    }

    /**
     * `total_f` es el resumen narrativo del escritorio (§7.6): existe en la tabla, pero **no** está en
     * `WRITABLE_COLUMNS`. Se descarta sin trabar el lote y el resto de las columnas se guarda.
     */
    public function test_una_columna_fuera_de_la_lista_blanca_se_descarta_y_el_resto_se_guarda(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'examen_fisico_nuevo',
            'operation' => 'created',
            'temp_id' => -44,
            'consulta_id' => $consulta->id,
            'columns' => [
                'total_f' => 'RESUMEN QUE ARMA EL ESCRITORIO',
                'colposcopia' => 'TEXTO DE OTRA SECCION',
                'tension' => '130/085',
                'neurologico' => 'CONSCIENTE, ORIENTADA',
            ],
        ]]);

        $this->assertSame([], $response->json('rechazados'), 'la creación igual entra');
        $fila = $this->fila($medico, $consulta);
        $this->assertNotNull($fila);
        $this->assertNull($fila->total_f, 'el resumen del escritorio no se escribe');
        $this->assertNull($fila->colposcopia, 'ni lo que es de otra sección');
        $this->assertSame('130/085', $fila->tension);
        $this->assertSame('CONSCIENTE, ORIENTADA', $fila->neurologico);
    }

    public function test_el_rastro_de_la_creacion_queda_con_source_mobile(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'examen_fisico_nuevo',
            'operation' => 'created',
            'temp_id' => -45,
            'consulta_id' => $consulta->id,
            'columns' => ['tension' => '125/075', 'general' => 'BUEN ESTADO'],
        ]]);

        $id = $this->creado($response, 'examen_fisico_nuevo', -45)['id'];

        $creacion = SyncChange::where('table_name', 'examen_fisico_nuevo')
            ->where('client_temp_id', -45)
            ->first();
        $this->assertNotNull($creacion, 'la creación deja su rastro con el id temporal');
        $this->assertSame('created', $creacion->operation);
        $this->assertSame($id, $creacion->record_id);
        $this->assertSame('mobile', $creacion->source);
        $this->assertSame($medico->reg_medico, $creacion->reg_medico);

        // Y el rastro por **columna** que deja la Action (el mismo que usa la web) también es del teléfono.
        $columnas = SyncChange::where('table_name', 'examen_fisico_nuevo')
            ->where('record_id', $id)
            ->where('operation', 'updated')
            ->orderBy('id')
            ->get();
        $this->assertEqualsCanonicalizing(
            ['tension', 'general'],
            $columnas->pluck('column_name')->all(),
            'una fila de rastro por columna escrita, como en la web',
        );
        $this->assertSame(['mobile', 'mobile'], $columnas->pluck('source')->all());
        $this->assertSame([$medico->reg_medico, $medico->reg_medico], $columnas->pluck('reg_medico')->all());
    }
}
