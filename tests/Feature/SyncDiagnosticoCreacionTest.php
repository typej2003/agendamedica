<?php

namespace Tests\Feature;

use App\Models\AntecePaciente;
use App\Models\Consulta;
use App\Models\Diagnostico;
use App\Models\DiagnosticoPaciente;
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
 * **A4a de PLAN-MOVIL.md: agregar un diagnóstico a una consulta desde el teléfono.**
 *
 * Quitar un diagnóstico ya funcionaba desde A1 (el `deleted` de `diagnostico_paciente` por el camino
 * genérico); lo que faltaba era el **alta** sin conexión. Lo que se fija acá:
 *
 *  1. Que la fila se cree con **los números de la consulta** —`nrohistoria` = `consultas.numhistoria` y
 *     `nroconsulta` = `consultas.nroconsulta`—, no con el id de `consultas`, y que el teléfono reciba el
 *     id real de la fila en `creados`.
 *  2. Que una consulta creada en el **mismo lote** (`consulta_temp_id`) se resuelva a sus números.
 *  3. Que el mismo código no se duplique en la misma consulta: el reintento devuelve el id que ya estaba.
 *  4. Que un código que **no está en el catálogo** del médico se rechace sin escribir nada.
 *  5. Que una consulta de **otro** médico se rechace sin escribir nada ni trabar el lote.
 *  6. Que el pedido puntual de una ficha (`volcado_paciente`) traiga el examen físico, los diagnósticos y
 *     los antecedentes, que es lo que se sumó a ese payload armado a mano.
 *  7. Que el rastro quede `source = mobile`, con el `reg_medico` de la consulta.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncDiagnosticoCreacionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-diagnostico-creacion-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-dx-' . uniqid() . '@example.com',
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
    private function consulta(Medico $medico, int $nroconsulta = 1): Consulta
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
            'nroconsulta' => $nroconsulta,
        ]);
    }

    /** Un código del catálogo `diagnosticos` del médico. */
    private function delCatalogo(Medico $medico, string $codigo = '0001', string $descripcion = 'LIE BAJO GRADO'): Diagnostico
    {
        return Diagnostico::create([
            'reg_medico' => $medico->reg_medico,
            'codediagnostico' => $codigo,
            'descripcion' => $descripcion,
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
    private function fila(Consulta $consulta, string $codigo = '0001'): ?DiagnosticoPaciente
    {
        return DiagnosticoPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->where('codediagnostico', $codigo)
            ->first();
    }

    public function test_crear_un_diagnostico_de_una_consulta_propia_deja_la_fila_con_los_numeros(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->delCatalogo($medico);

        $response = $this->sync([[
            'table' => 'diagnostico_paciente',
            'operation' => 'created',
            'temp_id' => -50,
            'consulta_id' => $consulta->id,
            'codediagnostico' => '0001',
        ]]);

        $fila = $this->fila($consulta);
        $this->assertNotNull($fila, 'el diagnóstico se creó');
        $this->assertSame((int) $consulta->numhistoria, $fila->nrohistoria, '`nrohistoria` es el número de historia');
        $this->assertSame((int) $consulta->nroconsulta, $fila->nroconsulta, '`nroconsulta` es el NÚMERO de la consulta');
        $this->assertNotSame($consulta->id, $fila->nroconsulta, 'no es el id de `consultas` (trampa del §7.4)');
        $this->assertSame('0001', trim((string) $fila->codediagnostico));
        $this->assertSame('LIE BAJO GRADO', $fila->detalle_diagnostco, 'el detalle se copia del catálogo');
        $this->assertSame(1, $fila->orden, 'el orden arranca en 1 en una consulta sin diagnósticos');
        $this->assertSame($medico->reg_medico, $fila->reg_medico);

        // Lo que el teléfono guarda como `remote_id`.
        $creado = $this->creado($response, 'diagnostico_paciente', -50);
        $this->assertNotNull($creado, 'la creación contesta con su id');
        $this->assertSame($fila->id, $creado['id']);
    }

    /** El detalle que manda el cliente (el DataWindow del escritorio lo deja editar) gana sobre el catálogo. */
    public function test_el_detalle_del_cliente_gana_sobre_la_descripcion_del_catalogo(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->delCatalogo($medico);

        $this->sync([[
            'table' => 'diagnostico_paciente',
            'operation' => 'created',
            'temp_id' => -51,
            'consulta_id' => $consulta->id,
            // La otra forma que acepta la creación: las columnas dentro de `columns`.
            'columns' => ['codediagnostico' => '0001', 'detalle_diagnostco' => 'LIE BAJO GRADO — endocervical'],
        ]]);

        $this->assertSame('LIE BAJO GRADO — endocervical', $this->fila($consulta)->detalle_diagnostco);
    }

    public function test_una_consulta_creada_en_el_mismo_lote_resuelve_a_sus_numeros(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->delCatalogo($medico);

        // Desordenados a propósito, como los manda un teléfono sin conexión: el servidor los reordena.
        $response = $this->sync([
            [
                'table' => 'diagnostico_paciente',
                'operation' => 'created',
                'temp_id' => -52,
                'consulta_temp_id' => -22,
                'codediagnostico' => '0001',
            ],
            [
                'table' => 'consultas',
                'operation' => 'created',
                'temp_id' => -22,
                'numhistoria' => $consulta->numhistoria,
                'fecha' => now()->toDateString(),
            ],
        ]);

        $creada = $this->creado($response, 'consultas', -22);
        $this->assertNotNull($creada, 'la consulta del lote se creó primero');
        $this->assertSame([], $response->json('rechazados'));

        $fila = DiagnosticoPaciente::where('reg_medico', $medico->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $creada['nroconsulta'])
            ->first();

        $this->assertNotNull($fila, 'el diagnóstico cuelga de la consulta del mismo lote');
        // La consulta del lote es la 2 de esa historia: la fila tiene que colgar de ese número y no del 1.
        $this->assertSame(2, $fila->nroconsulta);
        $this->assertSame($fila->id, $this->creado($response, 'diagnostico_paciente', -52)['id']);
    }

    /**
     * Dos formas de repetir la misma alta, las dos sin duplicar:
     *  - el **reintento** de la creación (mismo `temp_id`), que resuelve el rastro ya anotado;
     *  - el mismo código **con otro `temp_id`** (p. ej. dos teléfonos), que se resuelve por la clave del
     *    legado `(nrohistoria, nroconsulta, codediagnostico)`.
     */
    public function test_agregar_el_mismo_diagnostico_dos_veces_no_duplica_y_devuelve_el_id_existente(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->delCatalogo($medico);

        $cambio = [[
            'table' => 'diagnostico_paciente',
            'operation' => 'created',
            'temp_id' => -53,
            'consulta_id' => $consulta->id,
            'codediagnostico' => '0001',
        ]];

        $primera = $this->sync($cambio);
        $segunda = $this->sync($cambio);

        $this->assertSame(
            $this->creado($primera, 'diagnostico_paciente', -53),
            $this->creado($segunda, 'diagnostico_paciente', -53),
            'el reintento contesta lo mismo',
        );

        // Otro `temp_id` —otro teléfono, o la misma fila reenviada como nueva— tampoco la duplica.
        $tercera = $this->sync([[
            'table' => 'diagnostico_paciente',
            'operation' => 'created',
            'temp_id' => -54,
            'consulta_id' => $consulta->id,
            'codediagnostico' => '0001',
        ]]);

        $this->assertSame(
            $this->creado($primera, 'diagnostico_paciente', -53)['id'],
            $this->creado($tercera, 'diagnostico_paciente', -54)['id'],
            'la segunda alta del mismo código devuelve la fila que ya estaba',
        );
        $this->assertSame(1, DiagnosticoPaciente::where('reg_medico', $medico->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->count(), 'la fila no se duplica');
    }

    public function test_un_codigo_que_no_esta_en_el_catalogo_se_rechaza_y_no_escribe_nada(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        // El catálogo del médico tiene otro código: el que manda el teléfono no existe.
        $this->delCatalogo($medico, '0002', 'OTRO DIAGNOSTICO');

        $response = $this->sync([[
            'table' => 'diagnostico_paciente',
            'operation' => 'created',
            'temp_id' => -55,
            'consulta_id' => $consulta->id,
            'codediagnostico' => '0001',
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame('diagnostico_paciente', $response->json('rechazados.0.table'));
        $this->assertSame(-55, $response->json('rechazados.0.temp_id'));
        $this->assertSame(
            'El diagnóstico no está en el catálogo de este médico.',
            $response->json('rechazados.0.motivo'),
        );

        $this->assertSame(0, DiagnosticoPaciente::where('reg_medico', $medico->reg_medico)->count(),
            'no se escribió ninguna fila');
        $this->assertSame(0, SyncChange::where('table_name', 'diagnostico_paciente')
            ->where('client_temp_id', -55)
            ->count(), 'ni rastro de la creación rechazada');
    }

    public function test_una_consulta_de_otro_medico_se_rechaza_y_no_escribe_nada(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajena = $this->consulta($otro);
        $this->delCatalogo($otro);

        $this->medico();

        $response = $this->sync([[
            'table' => 'diagnostico_paciente',
            'operation' => 'created',
            'temp_id' => -56,
            'consulta_id' => $ajena->id,
            'codediagnostico' => '0001',
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame('diagnostico_paciente', $response->json('rechazados.0.table'));
        $this->assertSame(-56, $response->json('rechazados.0.temp_id'));
        $this->assertSame(
            'El diagnóstico no corresponde a una consulta de este médico.',
            $response->json('rechazados.0.motivo'),
        );

        $this->assertSame(0, DiagnosticoPaciente::where('nrohistoria', (int) $ajena->numhistoria)->count(),
            'no se escribió ninguna fila con la clave de la consulta ajena');
        $this->assertSame(0, SyncChange::where('table_name', 'diagnostico_paciente')
            ->where('client_temp_id', -56)
            ->count(), 'ni rastro de la creación rechazada');
    }

    public function test_el_rastro_de_la_creacion_queda_con_source_mobile(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->delCatalogo($medico);

        $response = $this->sync([[
            'table' => 'diagnostico_paciente',
            'operation' => 'created',
            'temp_id' => -57,
            'consulta_id' => $consulta->id,
            'codediagnostico' => '0001',
        ]]);

        $id = $this->creado($response, 'diagnostico_paciente', -57)['id'];

        $creacion = SyncChange::where('table_name', 'diagnostico_paciente')
            ->where('client_temp_id', -57)
            ->first();
        $this->assertNotNull($creacion, 'la creación deja su rastro con el id temporal');
        $this->assertSame('created', $creacion->operation);
        $this->assertSame($id, $creacion->record_id);
        $this->assertSame('mobile', $creacion->source);
        $this->assertSame($medico->reg_medico, $creacion->reg_medico);

        // Y el `created` que deja la Action (el mismo rastro que usa la web) también es del teléfono.
        $deLaAction = SyncChange::where('table_name', 'diagnostico_paciente')
            ->where('record_id', $id)
            ->whereNull('client_temp_id')
            ->where('operation', 'created')
            ->first();
        $this->assertNotNull($deLaAction, 'la Action deja su propio `created`');
        $this->assertSame('mobile', $deLaAction->source);
        $this->assertSame($medico->reg_medico, $deLaAction->reg_medico);
    }

    /**
     * **El pedido puntual de una ficha** (`volcado_paciente`) arma su payload a mano y no pasa por el plan
     * del volcado: hasta A4a no traía las tablas clínicas nuevas. Este paciente **no tiene ninguna cita en
     * la ventana** (no hay fila de `cola`), así que su historial no bajaría con el volcado paginado: es
     * exactamente el caso para el que existe este pedido.
     */
    public function test_el_volcado_de_una_ficha_fuera_de_la_ventana_trae_su_examen_y_sus_diagnosticos(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->delCatalogo($medico);

        ExamenFisico::create([
            'reg_medico' => $medico->reg_medico,
            'historia' => (int) $consulta->numhistoria,
            'consulta' => (int) $consulta->nroconsulta,
            'general' => 'BUEN ESTADO GENERAL',
        ]);
        DiagnosticoPaciente::create([
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'codediagnostico' => '0001',
            'detalle_diagnostco' => 'LIE BAJO GRADO',
            'orden' => 1,
        ]);
        AntecePaciente::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => (int) $consulta->numhistoria,
            'codeantecedente' => '0001',
            'detalles' => 'ANTECEDENTE DE PRUEBA',
        ]);

        $response = $this->postJson('/api/app/sync-app-data', [
            'volcado_paciente' => (int) $consulta->numhistoria,
        ])->assertOk();

        $this->assertNotEmpty($response->json('consultas'), 'la ficha trae sus consultas');
        $this->assertSame('BUEN ESTADO GENERAL', $response->json('examen_fisico_nuevo.0.general'));
        $this->assertSame('0001', trim((string) $response->json('diagnostico_paciente.0.codediagnostico')));
        $this->assertSame('ANTECEDENTE DE PRUEBA', $response->json('antece_paciente.0.detalles'));
    }
}
