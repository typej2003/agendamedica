<?php

namespace Tests\Feature;

use App\Models\Consulta;
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
 * **A4c de PLAN-MOVIL.md: las columnas clínicas viajan en la creación de la consulta.**
 *
 * El "Atender" del teléfono crea la consulta **sin conexión** y el médico escribe la enfermedad actual, la
 * evolución, el tratamiento (`consultas.laringe`) o la antropometría **antes** de que la consulta exista en
 * el servidor. Todavía no hay `remote_id`, así que esas columnas no pueden ir como `updated`: viajan dentro
 * de la creación (`columns`). Lo que se fija acá:
 *
 *  1. Que al crear la consulta las columnas de `WRITABLE_COLUMNS['consultas']` queden escritas, junto con
 *     el número que asigna el servidor y su rastro `created`.
 *  2. Que la antropometría (`peso`, `talla`, `fc`…) se guarde como número en `consultas`.
 *  3. Que lo que no está en la lista blanca (`resumen`) se descarte en silencio y el resto se guarde.
 *  4. Que el **reintento** de una creación ya aceptada aplique las columnas nuevas sobre la fila existente
 *     (rastro `updated`, `source = mobile`, last-write-wins) sin duplicar la consulta.
 *  5. Que una consulta de **otro** médico se rechace como siempre y no escriba nada.
 *  6. Que `columns` **no** pueda cambiar `numhistoria`, `nroconsulta`, `reg_medico` ni `fecha`: los resuelve
 *     el servidor.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncConsultaColumnasTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-consulta' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-consu' . substr(uniqid(), -6) . '@example.com',
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

    /** Un paciente del médico con su historia ya llena (la consulta la crea el test por el sync). */
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

    private function sync(array $changes)
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => $changes])->assertOk();
    }

    private function creado($response, string $tabla, int $tempId): ?array
    {
        return collect($response->json('creados'))
            ->first(fn ($c) => $c['table'] === $tabla && $c['temp_id'] === $tempId);
    }

    private function consulta(Historia $historia, int $nroconsulta = 1): ?Consulta
    {
        return Consulta::where('reg_medico', $historia->reg_medico)
            ->where('numhistoria', (int) $historia->numhistoria)
            ->where('nroconsulta', $nroconsulta)
            ->first();
    }

    public function test_crear_una_consulta_con_enfermedad_evolucion_y_tratamiento(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);

        $response = $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -60,
            'numhistoria' => $historia->numhistoria,
            'fecha' => '2026-01-15',
            'columns' => [
                'enfermedadactual' => 'REFIERE DOLOR PÉLVICO DE TRES DÍAS DE EVOLUCIÓN.',
                'evolucion' => 'MEJORÍA CON EL TRATAMIENTO INDICADO.',
                'laringe' => 'IBUPROFENO 400 MG CADA 8 HORAS POR 5 DÍAS.',
            ],
        ]]);

        $consulta = $this->consulta($historia);
        $this->assertNotNull($consulta, 'la consulta se creó');
        $this->assertSame((int) $historia->numhistoria, $consulta->numhistoria);
        $this->assertSame(1, $consulta->nroconsulta, 'el número lo asigna el servidor: es la primera de la historia');
        $this->assertSame('REFIERE DOLOR PÉLVICO DE TRES DÍAS DE EVOLUCIÓN.', $consulta->enfermedadactual);
        $this->assertSame('MEJORÍA CON EL TRATAMIENTO INDICADO.', $consulta->evolucion);
        $this->assertSame('IBUPROFENO 400 MG CADA 8 HORAS POR 5 DÍAS.', $consulta->laringe, 'el tratamiento es `laringe`');
        $this->assertSame('2026-01-15', $consulta->fecha->format('Y-m-d'));

        // La respuesta con el número real, que es lo que el teléfono guarda como `remote_id`.
        $creada = $this->creado($response, 'consultas', -60);
        $this->assertNotNull($creada);
        $this->assertSame($consulta->id, $creada['id']);
        $this->assertSame(1, $creada['nroconsulta']);

        // El rastro `created` de la creación, con su id temporal.
        $rastro = SyncChange::where('table_name', 'consultas')
            ->where('client_temp_id', -60)
            ->first();
        $this->assertNotNull($rastro, 'la creación deja su rastro con el id temporal');
        $this->assertSame('created', $rastro->operation);
        $this->assertSame($consulta->id, $rastro->record_id);
        $this->assertSame('mobile', $rastro->source);
        $this->assertSame($medico->reg_medico, $rastro->reg_medico);
    }

    public function test_crear_una_consulta_con_las_solapas_ginecologicas_de_texto(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);

        $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -61,
            'numhistoria' => $historia->numhistoria,
            'fecha' => '2026-01-16',
            'columns' => [
                'observaciones' => 'ZONA DE TRANSFORMACIÓN TIPO 1.',
                'faringe' => 'CITOLOGÍA NEGATIVA.',
                'cuello' => 'CAVIDAD UTERINA NORMAL.',
                'oido' => 'LABORATORIO SIN HALLAZGOS.',
            ],
        ]]);

        $consulta = $this->consulta($historia);
        $this->assertNotNull($consulta);
        $this->assertSame('ZONA DE TRANSFORMACIÓN TIPO 1.', $consulta->observaciones);
        $this->assertSame('CITOLOGÍA NEGATIVA.', $consulta->faringe);
        $this->assertSame('CAVIDAD UTERINA NORMAL.', $consulta->cuello);
        $this->assertSame('LABORATORIO SIN HALLAZGOS.', $consulta->oido);
    }

    public function test_crear_una_consulta_con_antropometria_guarda_los_numeros(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);

        $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -61,
            'numhistoria' => $historia->numhistoria,
            'columns' => [
                'peso' => '68.5',
                'talla' => '162',
                'fc' => '78',
            ],
        ]]);

        $consulta = $this->consulta($historia);
        $this->assertNotNull($consulta);
        $this->assertSame(68.5, $consulta->peso);
        $this->assertSame(162.0, $consulta->talla);
        $this->assertSame(78.0, $consulta->fc);
    }

    /**
     * `resumen` no es una columna de `WRITABLE_COLUMNS['consultas']`: se descarta sin trabar la creación y
     * el resto de las columnas se guarda.
     */
    public function test_una_columna_fuera_de_la_lista_blanca_se_descarta_y_el_resto_se_guarda(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);

        $response = $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -62,
            'numhistoria' => $historia->numhistoria,
            'columns' => [
                'resumen' => 'RESUMEN QUE NO SE DEBE GUARDAR',
                'enfermedadactual' => 'DOLOR ABDOMINAL.',
                'peso' => '70',
            ],
        ]]);

        $this->assertSame([], $response->json('rechazados'), 'la creación igual entra');
        $consulta = $this->consulta($historia);
        $this->assertNotNull($consulta);
        $this->assertSame('DOLOR ABDOMINAL.', $consulta->enfermedadactual);
        $this->assertSame(70.0, $consulta->peso);
        $this->assertNull($consulta->resumen, 'la columna descartada no se escribe');
    }

    /**
     * El reintento del mismo `temp_id` sobre una creación ya aceptada **también** aplica las columnas: si el
     * teléfono perdió la respuesta, la cola local ya está vacía y esos valores no volverían a viajar.
     */
    public function test_el_reintento_de_una_creacion_ya_aceptada_aplica_las_columnas_nuevas(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);

        // La creación original, sin columnas: se aceptó pero el teléfono perdió la respuesta.
        $primera = $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -63,
            'numhistoria' => $historia->numhistoria,
        ]]);
        $this->assertNotNull($this->creado($primera, 'consultas', -63));

        // El reintento con el mismo `temp_id` y las columnas que el médico escribió sin conexión.
        $segunda = $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -63,
            'numhistoria' => $historia->numhistoria,
            'columns' => [
                'enfermedadactual' => 'CEFALEA OCCIPITAL.',
                'evolucion' => 'SIN CAMBIOS.',
                'fc' => '82',
            ],
        ]]);

        $this->assertSame(
            $this->creado($primera, 'consultas', -63),
            $this->creado($segunda, 'consultas', -63),
            'el reintento contesta lo mismo y no renumera',
        );

        $this->assertSame(1, Consulta::where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', (int) $historia->numhistoria)
            ->count(), 'la consulta no se duplica');

        $consulta = $this->consulta($historia);
        $this->assertNotNull($consulta);
        $this->assertSame('CEFALEA OCCIPITAL.', $consulta->enfermedadactual);
        $this->assertSame('SIN CAMBIOS.', $consulta->evolucion);
        $this->assertSame(82.0, $consulta->fc);

        // El rastro de cada columna aplicada en el reintento: `updated` y del teléfono.
        $rastros = SyncChange::where('table_name', 'consultas')
            ->where('record_id', $consulta->id)
            ->where('operation', 'updated')
            ->orderBy('id')
            ->get();
        $this->assertEqualsCanonicalizing(
            ['enfermedadactual', 'evolucion', 'fc'],
            $rastros->pluck('column_name')->all(),
            'una fila de rastro por columna escrita',
        );
        $this->assertSame(['mobile', 'mobile', 'mobile'], $rastros->pluck('source')->all());
        $this->assertSame([$medico->reg_medico, $medico->reg_medico, $medico->reg_medico], $rastros->pluck('reg_medico')->all());
    }

    public function test_una_historia_de_otro_medico_se_rechaza_y_no_escribe_nada(): void
    {
        $otro = $this->medico(autenticar: false);
        $historiaAjena = $this->historia($otro);

        $this->medico();

        $response = $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -64,
            'numhistoria' => $historiaAjena->numhistoria,
            'columns' => ['enfermedadactual' => 'NO DEBERÍA ENTRAR'],
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame('consultas', $response->json('rechazados.0.table'));
        $this->assertSame(-64, $response->json('rechazados.0.temp_id'));
        $this->assertSame(
            'La consulta no corresponde a una historia de este médico.',
            $response->json('rechazados.0.motivo'),
        );

        $this->assertSame(0, Consulta::where('numhistoria', (int) $historiaAjena->numhistoria)->count(),
            'no se escribió ninguna consulta con la historia ajena');
        $this->assertSame(0, SyncChange::where('table_name', 'consultas')
            ->where('client_temp_id', -64)
            ->count(), 'ni rastro de la creación rechazada');
    }

    /**
     * `numhistoria` y `nroconsulta` son la clave del legado y el correlativo del servidor; `reg_medico` es el
     * tenant y `fecha` la resuelve la creación. Ninguno se acepta desde `columns`: se ignoran y la consulta
     * queda con lo que resolvió el servidor.
     */
    public function test_columns_no_puede_cambiar_el_numero_de_historia_ni_el_de_consulta(): void
    {
        $medico = $this->medico();
        $historia = $this->historia($medico);

        $this->sync([[
            'table' => 'consultas',
            'operation' => 'created',
            'temp_id' => -65,
            'numhistoria' => $historia->numhistoria,
            'fecha' => '2026-01-15',
            'columns' => [
                'numhistoria' => 123456789,
                'nroconsulta' => 42,
                'reg_medico' => 'OTRO-TENANT',
                'fecha' => '2000-01-01',
                'enfermedadactual' => 'DOLOR LUMBAR.',
            ],
        ]]);

        $consulta = $this->consulta($historia);
        $this->assertNotNull($consulta);
        $this->assertSame((int) $historia->numhistoria, $consulta->numhistoria, '`numhistoria` es el de la historia');
        $this->assertSame(1, $consulta->nroconsulta, '`nroconsulta` es el correlativo del servidor, no el de `columns`');
        $this->assertSame($medico->reg_medico, $consulta->reg_medico, 'el tenant sale del médico autenticado');
        $this->assertSame('2026-01-15', $consulta->fecha->format('Y-m-d'), 'la fecha es la de la creación');
        $this->assertSame('DOLOR LUMBAR.', $consulta->enfermedadactual, 'el resto de las columnas sí se guarda');
    }
}
