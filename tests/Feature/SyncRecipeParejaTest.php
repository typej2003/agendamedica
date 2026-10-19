<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\RecipePareja;
use App\Models\User;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A5a de PLAN-MOVIL.md §9: que el récipe de la pareja baje al teléfono.**
 *
 * `recipes_pareja` es la tabla legada del récipe que el médico le hace a la pareja del paciente
 * (WEB-5.1b). No tiene cabecera ni columna `recipe`: **una por consulta**, una fila por medicamento, y la
 * cédula y el nombre de la pareja se **repiten en todas** las filas.
 *
 * Lo que fija este test, por los cuatro caminos del sync:
 *
 *  1. **Delta** (`since`).
 *  2. **Volcado completo** (sin `since`).
 *  3. **Volcado paginado** (con la ventana de 60 días, medida por la cita).
 *  4. **Pedido puntual de una ficha** (`volcado_paciente`), que es la red de seguridad de la ventana.
 *
 * Y dos cosas que no son obvias:
 *
 * ⚠️ **La columna del número de historia es `nrohistoria`**, no `numhistoria`. `TablasLegado` devuelve
 * `numhistoria` por defecto, así que sin su entrada explícita el filtro pregunta por una columna que esta
 * tabla no tiene y **no baja nada** (es la trampa 122 de `TRAMPAS.md`, la de los 49.955 récipes).
 *
 * ⚠️ **No baja la pareja de otro `reg_medico`** con el mismo número de historia: el número es por médico.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncRecipeParejaTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-pareja-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'pareja-' . substr(uniqid(), -6) . '@example.com',
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

    /** Un paciente del médico con su historia y una consulta, con un número libre (no el sembrado). */
    private function consulta(Medico $medico, ?int $numHistoria = null): Consulta
    {
        $numHistoria = $numHistoria ?? random_int(900000000, 999999999);

        $paciente = Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
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

        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numHistoria,
            'nroconsulta' => 1,
        ]);
    }

    /**
     * La pareja de la consulta: dos medicamentos, con la cédula y el nombre **repetidos en las dos filas**
     * —así las escribe la Action de la web— y el `orden` desde 1.
     *
     * @return array<int,RecipePareja>
     */
    private function pareja(Medico $medico, Consulta $consulta, string $cedula = '12345678', string $nombre = 'Pareja De Prueba'): array
    {
        $comun = [
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'cedula' => $cedula,
            'nombre' => $nombre,
        ];

        return [
            RecipePareja::create($comun + [
                'codemedicina' => 'MED001',
                'indicaciones' => 'Una tableta cada 12 horas',
                'cantidad' => 10,
                'descripcion' => 'MEDICAMENTO UNO',
                'orden' => 1,
            ]),
            RecipePareja::create($comun + [
                'codemedicina' => 'MED002',
                'indicaciones' => 'Una tableta diaria',
                'cantidad' => 5,
                'descripcion' => 'MEDICAMENTO DOS',
                'orden' => 2,
            ]),
        ];
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_la_pareja_baja_por_delta_con_sus_dos_medicamentos(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // El delta se calcula por `updated_at`, así que el `since` tiene que ser anterior a las filas.
        $desde = now()->subMinute()->toIso8601String();
        $filas = $this->pareja($medico, $consulta);

        $r = $this->sync(['since' => $desde]);

        $this->assertCount(2, $r->json('recipes_pareja'), 'la pareja viaja con sus dos medicamentos');
        $this->assertSame(
            array_column(array_map(fn ($f) => $f->toArray(), $filas), 'id'),
            array_column($r->json('recipes_pareja'), 'id'),
        );

        // Los datos de la pareja, repetidos en las dos filas, y la clave del legado.
        foreach ($r->json('recipes_pareja') as $fila) {
            $this->assertSame('12345678', $fila['cedula']);
            $this->assertSame('Pareja De Prueba', $fila['nombre']);
            $this->assertSame($consulta->numhistoria, $fila['nrohistoria']);
            $this->assertSame($consulta->nroconsulta, $fila['nroconsulta']);
        }

        // Y lo propio de cada medicamento.
        $porCodigo = collect($r->json('recipes_pareja'))->keyBy('codemedicina');
        $this->assertSame('Una tableta cada 12 horas', $porCodigo['MED001']['indicaciones']);
        $this->assertSame(10, $porCodigo['MED001']['cantidad']);
        $this->assertSame(5, $porCodigo['MED002']['cantidad']);
        $this->assertSame(2, $porCodigo['MED002']['orden']);
    }

    public function test_no_baja_la_pareja_de_otro_medico_con_el_mismo_numero_de_historia(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $propias = $this->pareja($medico, $consulta);

        // El número de historia es **por médico**: esta fila tiene el mismo número y el mismo número de
        // consulta, y aun así no puede bajar a este teléfono.
        RecipePareja::create([
            'reg_medico' => 'otro-registro' . substr(uniqid(), -6),
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'codemedicina' => 'AJENO',
            'cedula' => '99999999',
            'nombre' => 'LA DEL OTRO MEDICO',
            'orden' => 1,
        ]);

        $r = $this->sync();

        $this->assertCount(2, $r->json('recipes_pareja'), 'sólo las filas propias');
        $this->assertSame(array_column(array_map(fn ($f) => $f->toArray(), $propias), 'id'), array_column($r->json('recipes_pareja'), 'id'));
        $this->assertNotContains(
            'LA DEL OTRO MEDICO',
            array_column($r->json('recipes_pareja'), 'nombre'),
            'la pareja del otro médico no se cuela con el mismo número de historia',
        );
    }

    public function test_el_volcado_completo_tambien_la_trae(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->pareja($medico, $consulta);

        // Sin `since` es la primera sincronización (o la descarga completa del app nuevo).
        $r = $this->sync();

        $this->assertCount(2, $r->json('recipes_pareja'), 'la pareja baja en el volcado completo');
        $this->assertSame('12345678', $r->json('recipes_pareja.0.cedula'));
        $this->assertSame('Pareja De Prueba', $r->json('recipes_pareja.0.nombre'));
    }

    /**
     * **El volcado paginado** aplica la ventana (`DIAS_DE_VENTANA`): lo clínico sólo viaja para los
     * pacientes que se atienden en esos días, y "se atiende" se mide por la cita (`cola.fecha`).
     */
    public function test_el_volcado_paginado_la_incluye_con_la_ventana_aplicada(): void
    {
        $medico = $this->medico();

        // Paciente de la ventana: tiene una cita de ayer.
        $enLaVentana = $this->consulta($medico);
        $this->pareja($medico, $enLaVentana);
        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDay()->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $enLaVentana->numhistoria,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Paciente de afuera: tiene su pareja pero ninguna cita en la ventana.
        $fuera = $this->consulta($medico);
        $this->pareja($medico, $fuera, '87654321', 'LA DE AFUERA');

        $vistos = [];
        $cursor = 'inicio';
        $paginas = 0;
        while ($cursor !== null && $paginas < 200) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 1])->json();
            // La clave tiene que venir siempre, aunque en este tramo no toque: el app recorre una lista
            // fija de tablas y no puede distinguir "vacía" de "no implementada".
            $this->assertArrayHasKey('recipes_pareja', $cuerpo);
            $vistos = array_merge($vistos, $cuerpo['recipes_pareja']);
            $cursor = $cuerpo['volcado_siguiente'];
            $paginas++;
        }

        $this->assertLessThan(200, $paginas, 'el volcado tiene que terminar');
        $this->assertCount(2, $vistos, 'sólo la pareja del paciente de la ventana');
        $this->assertSame(['12345678'], array_values(array_unique(array_column($vistos, 'cedula'))));
    }

    /**
     * **El pedido puntual de una ficha** (`volcado_paciente`) es la red de seguridad de la ventana: cuando
     * el médico abre un paciente que hace meses que no se atiende, llega también su récipe de la pareja.
     */
    public function test_el_pedido_de_una_ficha_fuera_de_la_ventana_trae_la_pareja(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->pareja($medico, $consulta);

        // La última cita fue hace 200 días: el volcado paginado no trajo nada de este paciente.
        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDays(200)->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $consulta->numhistoria,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cuerpo = $this->sync(['volcado_paciente' => $consulta->numhistoria])->json();

        $this->assertCount(2, $cuerpo['recipes_pareja'], 'la ficha fuera de la ventana trae su pareja');
        $this->assertSame('Pareja De Prueba', $cuerpo['recipes_pareja'][0]['nombre']);
    }

    /**
     * **La trampa del paso, fijada**: la columna del número de historia es `nrohistoria`.
     *
     * Se comprueba en el mapa de `TablasLegado` (la fuente única que consulta el filtro) y en el esquema
     * real: si alguien la borra del mapa, el default `numhistoria` haría que el filtro pregunte por una
     * columna que no existe y la pareja no bajaría nunca.
     */
    public function test_la_columna_de_historia_es_nrohistoria(): void
    {
        $this->assertSame('nrohistoria', TablasLegado::columnaHistoria('recipes_pareja'));
        $this->assertTrue(Schema::hasColumn('recipes_pareja', 'nrohistoria'));
        $this->assertTrue(Schema::hasColumn('recipes_pareja', 'nroconsulta'));
        $this->assertFalse(Schema::hasColumn('recipes_pareja', 'numhistoria'), 'la tabla no tiene `numhistoria`');
        // Y la de la consulta: el número dentro de la historia, no el id.
        $this->assertFalse(Schema::hasColumn('recipes_pareja', 'consulta'), 'la tabla no tiene `consulta`');
        $this->assertFalse(Schema::hasColumn('recipes_pareja', 'recipe'), 'la pareja no lleva número de récipe');
    }

    // ------------------------------------------------------------------------------------------------
    // A5b: la escritura, desde el teléfono
    // ------------------------------------------------------------------------------------------------

    /** El cuerpo de una creación de la pareja, como lo arma `SyncSchema` del teléfono. */
    private function creacion(Consulta $consulta, int $tempId, array $items = [
        ['codemedicina' => 'MED001', 'cantidad' => 10, 'indicaciones' => 'Una cada 12 horas', 'descripcion' => 'MEDICAMENTO UNO'],
        ['codemedicina' => 'MED002', 'cantidad' => 5, 'indicaciones' => 'Una diaria', 'descripcion' => 'MEDICAMENTO DOS'],
    ], string $cedula = '12345678', string $nombre = 'Pareja De Prueba'): array
    {
        return [
            'table' => 'recipes_pareja',
            'operation' => 'created',
            'temp_id' => $tempId,
            'consulta_id' => $consulta->id,
            'cedula' => $cedula,
            'nombre' => $nombre,
            'items' => $items,
        ];
    }

    public function test_el_telefono_crea_la_pareja_con_sus_medicamentos(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $r = $this->sync(['changes' => [$this->creacion($consulta, 900001)]]);

        // La respuesta le dice al teléfono cuál es su creación (para soltar la fila provisional).
        $this->assertSame('recipes_pareja', $r->json('creados.0.table'));
        $this->assertSame(900001, $r->json('creados.0.temp_id'));
        $this->assertNotNull($r->json('creados.0.id'));
        $this->assertSame([], $r->json('rechazados'));

        // Y las filas quedaron con la clave del legado y la pareja repetida en las dos.
        $filas = RecipePareja::where('reg_medico', $medico->reg_medico)
            ->where('nrohistoria', $consulta->numhistoria)
            ->where('nroconsulta', $consulta->nroconsulta)
            ->orderBy('orden')
            ->get();

        $this->assertCount(2, $filas);
        foreach ($filas as $fila) {
            $this->assertSame('12345678', $fila->cedula);
            $this->assertSame('Pareja De Prueba', $fila->nombre);
        }
        $this->assertSame(['MED001', 'MED002'], $filas->pluck('codemedicina')->all());
        $this->assertSame(10, (int) $filas[0]->cantidad);
        $this->assertSame('Una cada 12 horas', $filas[0]->indicaciones);
        $this->assertSame([1, 2], $filas->pluck('orden')->map(fn ($o) => (int) $o)->all());

        // El rastro: un `created` con el id temporal del lote, que es lo que protege estas filas del
        // reemplazo de la tabla que hace el escritorio.
        $this->assertSame(1, DB::table('sync_changes')
            ->where('table_name', 'recipes_pareja')
            ->where('client_temp_id', 900001)
            ->where('operation', 'created')
            ->where('source', 'mobile')
            ->count());
    }

    public function test_el_reintento_con_el_mismo_temp_id_no_duplica_y_devuelve_el_mismo_id(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $cambio = $this->creacion($consulta, 900002);

        $primera = $this->sync(['changes' => [$cambio]]);
        $segunda = $this->sync(['changes' => [$cambio]]);

        $this->assertSame($primera->json('creados.0.id'), $segunda->json('creados.0.id'), 'el reintento devuelve el mismo id');
        $this->assertSame(2, RecipePareja::where('reg_medico', $medico->reg_medico)
            ->where('nrohistoria', $consulta->numhistoria)->count(), 'no se duplican las filas');
    }

    /**
     * **Corregir una pareja que ya existe**: la Action compara por `codemedicina`, así que una segunda
     * creación sobre la misma consulta actualiza las filas que siguen y quita las que ya no vienen, en vez
     * de crear un récipe al lado. El teléfono no manda esto hoy (una pareja ya subida es de solo lectura),
     * pero el servidor no puede duplicar si algún día lo hace o si la pareja la cargó la web.
     */
    public function test_una_segunda_creacion_corrige_en_el_lugar_en_vez_de_duplicar(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sync(['changes' => [$this->creacion($consulta, 900003)]]);
        $r = $this->sync(['changes' => [$this->creacion(
            $consulta,
            900004,
            [['codemedicina' => 'MED001', 'cantidad' => 20, 'indicaciones' => 'Cambiada', 'descripcion' => 'MEDICAMENTO UNO']],
            '87654321',
            'Otra Pareja',
        )]]);

        $this->assertSame([], $r->json('rechazados'));
        $filas = RecipePareja::where('reg_medico', $medico->reg_medico)
            ->where('nrohistoria', $consulta->numhistoria)
            ->get();

        $this->assertCount(1, $filas, 'queda un solo medicamento: el otro se quitó');
        $this->assertSame('MED001', $filas[0]->codemedicina);
        $this->assertSame(20, (int) $filas[0]->cantidad);
        $this->assertSame('Cambiada', $filas[0]->indicaciones);
        // La pareja se reescribe en todas las filas.
        $this->assertSame('87654321', $filas[0]->cedula);
        $this->assertSame('Otra Pareja', $filas[0]->nombre);
    }

    public function test_sin_la_cedula_o_el_nombre_la_creacion_se_rechaza_con_su_motivo(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $r = $this->sync(['changes' => [
            $this->creacion($consulta, 900005, nombre: ''),
        ]]);

        $this->assertSame([], $r->json('creados'));
        $this->assertSame('recipes_pareja', $r->json('rechazados.0.table'));
        $this->assertSame(900005, $r->json('rechazados.0.temp_id'));
        $this->assertStringContainsString('pareja', $r->json('rechazados.0.motivo'));
        $this->assertSame(0, RecipePareja::where('nrohistoria', $consulta->numhistoria)->count(), 'no se escribió nada');
    }

    public function test_una_pareja_sin_medicamentos_se_rechaza(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $r = $this->sync(['changes' => [$this->creacion($consulta, 900006, items: [])]]);

        $this->assertSame([], $r->json('creados'));
        $this->assertStringContainsString('medicamentos', $r->json('rechazados.0.motivo'));
    }

    public function test_la_pareja_de_una_consulta_de_otro_medico_se_rechaza(): void
    {
        // La consulta es de otro médico (otro `reg_medico`); el teléfono no la puede usar.
        $ajeno = $this->medico(autenticar: false);
        $consultaAjena = $this->consulta($ajeno);
        $medico = $this->medico();

        $r = $this->sync(['changes' => [$this->creacion($consultaAjena, 900007)]]);

        $this->assertSame([], $r->json('creados'));
        $this->assertStringContainsString('no corresponde', $r->json('rechazados.0.motivo'));
        $this->assertSame(0, RecipePareja::where('reg_medico', $ajeno->reg_medico)->count());
    }

    /**
     * **La creación cuelga de una consulta creada en el mismo lote** (`consulta_temp_id`): es el caso real
     * del teléfono —"Atender" abre la consulta sin conexión y el médico carga la pareja antes de que esa
     * consulta exista en el servidor—. El servidor ordena las creaciones por dependencia, así que la
     * consulta ya tiene sus números cuando la Action de la pareja los busca.
     */
    public function test_la_creacion_resuelve_una_consulta_creada_en_el_mismo_lote(): void
    {
        $medico = $this->medico();
        $historia = $this->consulta($medico);

        $r = $this->sync(['changes' => [
            [
                'table' => 'consultas',
                'operation' => 'created',
                'temp_id' => 900010,
                'numhistoria' => $historia->numhistoria,
                'fecha' => now()->toDateString(),
            ],
            [
                'table' => 'recipes_pareja',
                'operation' => 'created',
                'temp_id' => 900011,
                'consulta_temp_id' => 900010,
                'cedula' => '11223344',
                'nombre' => 'Pareja Del Mismo Lote',
                'items' => [['codemedicina' => 'MED001', 'cantidad' => 3, 'indicaciones' => 'Una diaria']],
            ],
        ]]);

        $this->assertSame([], $r->json('rechazados'));

        $creadas = collect($r->json('creados'))->keyBy('temp_id');
        $this->assertTrue($creadas->has(900010), 'se creó la consulta');
        $this->assertTrue($creadas->has(900011), 'se creó la pareja');

        // Y la pareja quedó con los números de la consulta nueva.
        $nueva = Consulta::find($creadas[900010]['id']);
        $this->assertSame(1, RecipePareja::where('nrohistoria', $nueva->numhistoria)
            ->where('nroconsulta', $nueva->nroconsulta)
            ->where('nombre', 'Pareja Del Mismo Lote')
            ->count());
    }
}
