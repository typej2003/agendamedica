<?php

namespace Tests\Feature;

use App\Models\Antecedente;
use App\Models\AntecePaciente;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\SyncChange;
use App\Models\TipoAntecedente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A4b de PLAN-MOVIL.md: los antecedentes del paciente desde el teléfono.**
 *
 * La baja ya funcionaba desde A1 (el `deleted` de `antece_paciente` por el camino genérico); lo que
 * faltaba era lo demás: que el teléfono **baje** los dos catálogos y las filas del paciente, y que el
 * **alta** y la **edición** entren por el sync. Lo que se fija acá:
 *
 *  1. Que el delta y el volcado completo traigan `antecedentes`, `tipo_antecedente` y `antece_paciente`
 *     **del médico**, y no los de otro `reg_medico` (aunque el número de historia coincida: el número es
 *     por médico).
 *  2. Que la **ventana** del volcado paginado aplique a `antece_paciente`: un paciente sin citas en los
 *     últimos 60 días no baja sus antecedentes en el paginado (sí en `volcado_paciente`, la red de
 *     seguridad que ya cubre A4a).
 *  3. El alta con `numhistoria` existente y con `historia_temp_id` del mismo lote, con la descripción y el
 *     tipo copiados del catálogo y la casilla de Alerta (`tipo2`) si vino, y el id real en `creados`.
 *  4. Que la misma alta repetida **no duplique**, y que un código fuera del catálogo se rechace sin
 *     escribir nada.
 *  5. La edición de `detalles` y de `tipo2` por el camino de A1 (`updated`), con su rastro `source =
 *     mobile`.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncAntecedentesTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-antecede' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-ant-' . substr(uniqid(), -6) . '@example.com',
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

    /** Un paciente del médico con su historia. El número se elige libre (es único). */
    private function historia(Medico $medico): Historia
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

        return Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);
    }

    /** Un tipo del catálogo (`tipo_antecedente`): es el que agrupa y el que el antecedente copia. */
    private function tipo(Medico $medico, string $codigo = '01', string $descripcion = 'PERSONAL'): TipoAntecedente
    {
        return TipoAntecedente::create([
            'reg_medico' => $medico->reg_medico,
            'codetipo' => $codigo,
            'descripcion' => $descripcion,
            'tipoantecedente' => substr($codigo, -1),
        ]);
    }

    /** Un antecedente del catálogo `antecedentes` del médico. */
    private function delCatalogo(
        Medico $medico,
        string $codigo = '0001',
        string $descripcion = 'CANCER',
        string $codtipo = '01',
    ): Antecedente {
        return Antecedente::create([
            'reg_medico' => $medico->reg_medico,
            'codantecedente' => $codigo,
            'descripcion' => $descripcion,
            'codtipo' => $codtipo,
        ]);
    }

    /** Una fila de `antece_paciente`: la relación del paciente con un antecedente del catálogo. */
    private function antecedente(Medico $medico, Historia $historia, array $valores = []): AntecePaciente
    {
        return AntecePaciente::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => (int) $historia->numhistoria,
            'codeantecedente' => '0001',
            'detalles' => 'ANTECEDENTE DE PRUEBA',
            'descripcion' => 'CANCER',
            'tipo' => '01',
            'tipo2' => null,
        ], $valores));
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    /** Manda un lote de cambios, que es lo que viaja en `changes`. */
    private function enviar(array $changes)
    {
        return $this->sync(['changes' => $changes]);
    }

    private function creado($response, string $tabla, int $tempId): ?array
    {
        return collect($response->json('creados'))
            ->first(fn ($c) => $c['table'] === $tabla && $c['temp_id'] === $tempId);
    }

    /** La fila del paciente, por la clave del legado (`reg_medico` + `numhistoria` + `codeantecedente`). */
    private function fila(Medico $medico, $numhistoria, string $codigo = '0001'): ?AntecePaciente
    {
        return AntecePaciente::where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', (int) $numhistoria)
            ->where('codeantecedente', $codigo)
            ->first();
    }

    /** Recorre el volcado paginado entero y devuelve las filas de cada tabla. */
    private function recorrerVolcado(): array
    {
        $vistas = [];
        $cursor = 'inicio';
        $paginas = 0;

        while ($cursor !== null && $paginas < 200) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 500])->json();

            foreach (['antecedentes', 'tipo_antecedente', 'antece_paciente'] as $clave) {
                foreach ((array) $cuerpo[$clave] as $fila) {
                    $vistas[$clave][] = $fila;
                }
            }

            $cursor = $cuerpo['volcado_siguiente'];
            $paginas++;
        }

        $this->assertLessThan(200, $paginas, 'el volcado tiene que terminar');

        return $vistas;
    }

    public function test_el_delta_trae_los_catalogos_y_los_antecedentes_del_medico(): void
    {
        $medico = $this->medico();
        $tipo = $this->tipo($medico);
        $catalogo = $this->delCatalogo($medico);
        $historia = $this->historia($medico);
        $fila = $this->antecedente($medico, $historia, ['detalles' => 'MADRE CON CANCER DE MAMA', 'tipo2' => 'A']);

        // El de otro médico, con el **mismo número de historia**: el número es por médico, así que nada
        // de esto puede bajar al teléfono de la doctora.
        $otro = $this->medico(autenticar: false);
        $this->tipo($otro, '01', 'PERSONAL AJENO');
        $this->delCatalogo($otro, '0001', 'CANCER AJENO');
        $this->antecedente($otro, $historia, ['detalles' => 'DEL OTRO MEDICO']);

        // El delta se calcula por `updated_at`, así que el `since` tiene que ser anterior a las filas.
        $r = $this->sync(['since' => now()->subMinute()->toIso8601String()]);

        $this->assertSame([$catalogo->id], array_column($r->json('antecedentes'), 'id'), 'sólo el catálogo propio');
        $this->assertSame([$tipo->id], array_column($r->json('tipo_antecedente'), 'id'), 'sólo los tipos propios');

        $this->assertCount(1, $r->json('antece_paciente'), 'sólo el antecedente propio');
        $antecedente = $r->json('antece_paciente.0');
        $this->assertSame($fila->id, $antecedente['id']);
        $this->assertSame((int) $historia->numhistoria, (int) $antecedente['numhistoria']);
        $this->assertSame('0001', trim((string) $antecedente['codeantecedente']));
        $this->assertSame('MADRE CON CANCER DE MAMA', $antecedente['detalles']);
        $this->assertSame('CANCER', $antecedente['descripcion']);
        $this->assertSame('01', $antecedente['tipo']);
        $this->assertSame('A', $antecedente['tipo2'], 'la casilla de Alerta baja tal cual está');
    }

    public function test_el_volcado_completo_tambien_los_trae(): void
    {
        $medico = $this->medico();
        $this->tipo($medico);
        $catalogo = $this->delCatalogo($medico);
        $historia = $this->historia($medico);
        $fila = $this->antecedente($medico, $historia);

        // Sin `since` es la primera sincronización (o la descarga completa de un app viejo).
        $r = $this->sync();

        $this->assertSame([$catalogo->id], array_column($r->json('antecedentes'), 'id'));
        $this->assertSame([$fila->id], array_column($r->json('antece_paciente'), 'id'));
        $this->assertNotEmpty($r->json('tipo_antecedente'));
        $this->assertSame('CANCER', $r->json('antece_paciente.0.descripcion'));
    }

    /**
     * **La ventana del volcado paginado** (`DIAS_DE_VENTANA`): "se atiende" se mide por la cita
     * (`cola.fecha`), así que un paciente sin citas recientes no baja sus antecedentes. El pedido puntual
     * de su ficha (`volcado_paciente`) sí los trae: es la red de seguridad de esa ventana.
     */
    public function test_la_ventana_del_volcado_paginado_aplica_a_antece_paciente(): void
    {
        $medico = $this->medico();
        $this->delCatalogo($medico);
        $enLaVentana = $this->historia($medico);
        $viejo = $this->historia($medico);

        $this->antecedente($medico, $enLaVentana, ['detalles' => 'DE LA VENTANA']);
        $this->antecedente($medico, $viejo, ['detalles' => 'VIEJO']);

        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDay()->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $enLaVentana->numhistoria,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paginas = $this->recorrerVolcado();
        $detalles = array_column($paginas['antece_paciente'] ?? [], 'detalles');

        $this->assertContains('DE LA VENTANA', $detalles, 'el paciente de la ventana baja sus antecedentes');
        $this->assertNotContains('VIEJO', $detalles, 'el que no se atiende en la ventana, no');

        // La ficha se pide aparte y los trae igual: es lo que cubre el caso del paciente viejo.
        $r = $this->postJson('/api/app/sync-app-data', [
            'volcado_paciente' => (int) $viejo->numhistoria,
        ])->assertOk();

        $this->assertSame('VIEJO', $r->json('antece_paciente.0.detalles'));
    }

    public function test_el_alta_con_numhistoria_crea_la_fila_con_la_descripcion_y_el_tipo_del_catalogo(): void
    {
        $medico = $this->medico();
        $this->tipo($medico);
        $this->delCatalogo($medico);
        $historia = $this->historia($medico);

        $response = $this->enviar([[
            'table' => 'antece_paciente',
            'operation' => 'created',
            'temp_id' => -60,
            'numhistoria' => (int) $historia->numhistoria,
            'codeantecedente' => '0001',
            'columns' => [
                'detalles' => 'MADRE CON CANCER DE MAMA',
                'tipo2' => 'A',
            ],
        ]]);

        $this->assertSame([], $response->json('rechazados'));

        $fila = $this->fila($medico, $historia->numhistoria);
        $this->assertNotNull($fila, 'el antecedente se creó');
        $this->assertSame('0001', trim((string) $fila->codeantecedente));
        $this->assertSame('MADRE CON CANCER DE MAMA', $fila->detalles);
        $this->assertSame('CANCER', $fila->descripcion, 'la descripción se copia del catálogo');
        $this->assertSame('01', $fila->tipo, 'y el tipo es el `codtipo` del catálogo');
        $this->assertSame('A', $fila->tipo2, 'la casilla de Alerta, si vino');

        // Lo que el teléfono guarda como `remote_id`.
        $creado = $this->creado($response, 'antece_paciente', -60);
        $this->assertNotNull($creado, 'la creación contesta con su id');
        $this->assertSame($fila->id, $creado['id']);

        // El rastro de la creación queda con el id temporal y `source = mobile`.
        $rastro = SyncChange::where('table_name', 'antece_paciente')
            ->where('client_temp_id', -60)
            ->first();
        $this->assertNotNull($rastro, 'la creación deja su rastro con el id temporal');
        $this->assertSame($fila->id, $rastro->record_id);
        $this->assertSame('mobile', $rastro->source);
        $this->assertSame($medico->reg_medico, $rastro->reg_medico);
    }

    /** Una historia sin tocar la casilla nace en `null`: guardarla no la ensucia (§3 de A4b). */
    public function test_la_casilla_de_alerta_queda_en_null_si_no_viene(): void
    {
        $medico = $this->medico();
        $this->delCatalogo($medico);
        $historia = $this->historia($medico);

        $this->enviar([[
            'table' => 'antece_paciente',
            'operation' => 'created',
            'temp_id' => -61,
            'numhistoria' => (int) $historia->numhistoria,
            'codeantecedente' => '0001',
        ]]);

        $fila = $this->fila($medico, $historia->numhistoria);
        $this->assertNotNull($fila);
        $this->assertNull($fila->tipo2);
        $this->assertSame('', (string) $fila->detalles, 'el detalle nace vacío, como en el escritorio');
    }

    public function test_el_alta_con_historia_temp_id_resuelve_la_historia_del_lote(): void
    {
        $medico = $this->medico();
        $this->tipo($medico);
        $this->delCatalogo($medico);

        // El paciente recién dado de alta en el teléfono: sin número de historia todavía.
        $paciente = Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'Sin Historia',
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => null,
        ]);

        // Desordenados a propósito, como los manda un teléfono sin conexión: el servidor los reordena.
        $response = $this->enviar([
            [
                'table' => 'antece_paciente',
                'operation' => 'created',
                'temp_id' => -62,
                'historia_temp_id' => -32,
                'codeantecedente' => '0001',
                'columns' => ['detalles' => 'PADRE DIABETICO'],
            ],
            [
                'table' => 'historias',
                'operation' => 'created',
                'temp_id' => -32,
                'paciente_id' => $paciente->id,
            ],
        ]);

        $creada = $this->creado($response, 'historias', -32);
        $this->assertNotNull($creada, 'la historia del lote se creó primero');
        $this->assertSame([], $response->json('rechazados'));

        $fila = $this->fila($medico, $creada['numhistoria']);
        $this->assertNotNull($fila, 'el antecedente cuelga de la historia del mismo lote');
        $this->assertSame($paciente->id, Historia::find($creada['id'])->paciente_id);
        $this->assertSame('PADRE DIABETICO', $fila->detalles);
        $this->assertSame('CANCER', $fila->descripcion);
        $this->assertSame($fila->id, $this->creado($response, 'antece_paciente', -62)['id']);
    }

    /**
     * Dos formas de repetir la misma alta, las dos sin duplicar:
     *  - el **reintento** de la creación (mismo `temp_id`), que resuelve el rastro ya anotado;
     *  - el mismo código **con otro `temp_id`** (p. ej. dos teléfonos), que se resuelve por la clave del
     *    legado `(reg_medico, numhistoria, codeantecedente)`.
     */
    public function test_la_misma_alta_dos_veces_no_duplica_y_devuelve_el_id_existente(): void
    {
        $medico = $this->medico();
        $this->delCatalogo($medico);
        $historia = $this->historia($medico);

        $cambio = [[
            'table' => 'antece_paciente',
            'operation' => 'created',
            'temp_id' => -63,
            'numhistoria' => (int) $historia->numhistoria,
            'codeantecedente' => '0001',
        ]];

        $primera = $this->enviar($cambio);
        $segunda = $this->enviar($cambio);

        $this->assertSame(
            $this->creado($primera, 'antece_paciente', -63),
            $this->creado($segunda, 'antece_paciente', -63),
            'el reintento contesta lo mismo',
        );

        $tercera = $this->enviar([[
            'table' => 'antece_paciente',
            'operation' => 'created',
            'temp_id' => -64,
            'numhistoria' => (int) $historia->numhistoria,
            'codeantecedente' => '0001',
        ]]);

        $this->assertSame(
            $this->creado($primera, 'antece_paciente', -63)['id'],
            $this->creado($tercera, 'antece_paciente', -64)['id'],
            'la segunda alta del mismo código devuelve la fila que ya estaba',
        );
        $this->assertSame(1, AntecePaciente::where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', (int) $historia->numhistoria)
            ->count(), 'la fila no se duplica');
    }

    public function test_un_codigo_que_no_esta_en_el_catalogo_se_rechaza_y_no_escribe_nada(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);
        // El catálogo del médico tiene otro código: el que manda el teléfono no existe.
        $this->delCatalogo($medico, '0002', 'OTRO ANTECEDENTE');

        $response = $this->enviar([[
            'table' => 'antece_paciente',
            'operation' => 'created',
            'temp_id' => -65,
            'numhistoria' => (int) $historia->numhistoria,
            'codeantecedente' => '0001',
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame('antece_paciente', $response->json('rechazados.0.table'));
        $this->assertSame(-65, $response->json('rechazados.0.temp_id'));
        $this->assertSame(
            'El antecedente no está en el catálogo de este médico.',
            $response->json('rechazados.0.motivo'),
        );

        $this->assertSame(0, AntecePaciente::where('reg_medico', $medico->reg_medico)->count(),
            'no se escribió ninguna fila');
        $this->assertSame(0, SyncChange::where('table_name', 'antece_paciente')
            ->where('client_temp_id', -65)
            ->count(), 'ni rastro de la creación rechazada');
    }

    public function test_una_historia_de_otro_medico_se_rechaza_y_no_escribe_nada(): void
    {
        $otro = $this->medico(autenticar: false);
        $this->delCatalogo($otro);
        $ajena = $this->historia($otro);

        $this->medico();

        $response = $this->enviar([[
            'table' => 'antece_paciente',
            'operation' => 'created',
            'temp_id' => -66,
            'numhistoria' => (int) $ajena->numhistoria,
            'codeantecedente' => '0001',
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame(
            'El antecedente no corresponde a una historia de este médico.',
            $response->json('rechazados.0.motivo'),
        );

        $this->assertSame(0, AntecePaciente::where('numhistoria', (int) $ajena->numhistoria)->count(),
            'no se escribió ninguna fila con el número de historia ajeno');
    }

    /**
     * La edición del detalle y de la casilla de Alerta va por el camino de A1 (`updated`), el genérico:
     * sólo las columnas de `WRITABLE_COLUMNS['antece_paciente']`, con last-write-wins y su rastro en
     * `sync_changes` con `source = mobile`.
     */
    public function test_editar_el_detalle_y_la_casilla_de_alerta_aplica_y_deja_rastro(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);
        $fila = $this->antecedente($medico, $historia);

        $this->enviar([
            [
                'table' => 'antece_paciente',
                'operation' => 'updated',
                'record_id' => $fila->id,
                'column' => 'detalles',
                'value' => 'ANTECEDENTE CORREGIDO EN EL TELEFONO',
            ],
            [
                'table' => 'antece_paciente',
                'operation' => 'updated',
                'record_id' => $fila->id,
                'column' => 'tipo2',
                'value' => 'A',
            ],
        ]);

        $fila->refresh();
        $this->assertSame('ANTECEDENTE CORREGIDO EN EL TELEFONO', $fila->detalles);
        $this->assertSame('A', $fila->tipo2, 'la casilla de Alerta se marca desde el teléfono');

        $rastros = SyncChange::where('table_name', 'antece_paciente')
            ->where('record_id', $fila->id)
            ->where('operation', 'updated')
            ->orderBy('id')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['detalles', 'tipo2'],
            $rastros->pluck('column_name')->all(),
            'una fila de rastro por columna escrita',
        );
        $this->assertSame(['mobile', 'mobile'], $rastros->pluck('source')->all());
        $this->assertSame([$medico->reg_medico, $medico->reg_medico], $rastros->pluck('reg_medico')->all());
    }
}
