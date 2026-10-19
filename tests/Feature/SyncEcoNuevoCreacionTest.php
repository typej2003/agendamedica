<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoMamario;
use App\Http\Requests\SyncAppDataRequest;
use App\Models\Consulta;
use App\Models\EcoCardiogramaFetal as ModeloFetal;
use App\Models\EcoDoppler as ModeloDoppler;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\SyncChange;
use App\Models\UltraAbdominal as ModeloAbdominal;
use App\Models\UltraMama as ModeloMamario;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A6c de PLAN-MOVIL.md §10: los otros cuatro estudios ecográficos se cargan desde el teléfono.**
 *
 * Es la mitad de escritura de la ola: el Doppler perinatal (`eco_doppler`), el mamario (`ultra_mama`), el
 * abdominal (`ultra_abdominal`) y el ecocardiograma fetal (`ecocadiograma_fetal`, con el typo del legado).
 *
 * Lo que se fija, con un conjunto de datos por estudio:
 *
 *  1. Crear deja la fila **en su tabla**, con la clave del legado (`historia` + `consulta`) y el valor
 *     cargado.
 *  2. El reintento con el mismo `temp_id` **no duplica** y devuelve **el mismo id**.
 *  3. Una columna **fuera del mapa** se descarta y el resto se guarda.
 *  4. Una consulta de **otro** médico se rechaza sin escribir nada.
 *  5. La edición por `updated` (camino de A1) aplica y deja su rastro `source = mobile`.
 *  6. Los cuatro **no se pisan entre sí**: son cuatro tablas y pueden convivir en la misma consulta.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncEcoNuevoCreacionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-eco10-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'eco10-' . substr(uniqid(), -6) . '@example.com',
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

    /**
     * Un caso por estudio: su tabla, su modelo, su lectura (la que encuentra la fila por la clave del
     * legado) y una **columna propia del mapa** con su valor. La columna tiene que estar en el mapa: lo que
     * no está se descarta (y el test de la columna desconocida lo comprueba).
     *
     * @return array<string,array{clase:class-string,modelo:class-string,lectura:class-string,columna:string,valor:string}>
     */
    public static function estudios(): array
    {
        return [
            'doppler' => [
                'clase' => 'eco_doppler',
                'modelo' => ModeloDoppler::class,
                'lectura' => EcoDoppler::class,
                'columna' => 'conclusion',
                'valor' => 'DOPPLER SIN ALTERACIONES',
            ],
            'mamario' => [
                'clase' => 'ultra_mama',
                'modelo' => ModeloMamario::class,
                'lectura' => EcoMamario::class,
                'columna' => 'conclusion',
                'valor' => 'MAMAS SIN LESIONES',
            ],
            'abdominal' => [
                'clase' => 'ultra_abdominal',
                'modelo' => ModeloAbdominal::class,
                'lectura' => EcoAbdominal::class,
                'columna' => 'conclusiones',
                'valor' => 'ABDOMEN NORMAL',
            ],
            'fetal' => [
                'clase' => 'ecocadiograma_fetal',
                'modelo' => ModeloFetal::class,
                'lectura' => EcoCardiogramaFetal::class,
                'columna' => 'conclusion',
                'valor' => 'CORAZON FETAL NORMAL',
            ],
        ];
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

    /** La fila del estudio de esa consulta, buscada como lo hace el app (por `historia` + `consulta`). */
    private function fila(string $modelo, Medico $medico, Consulta $consulta): ?Model
    {
        return $modelo::where('reg_medico', $medico->reg_medico)
            ->where('historia', $consulta->numhistoria)
            ->where('consulta', $consulta->nroconsulta)
            ->first();
    }

    /** @dataProvider estudios */
    public function test_crear_un_estudio_deja_la_fila_en_su_tabla_con_la_clave_del_legado(
        string $tabla,
        string $modelo,
        string $lectura,
        string $columna,
        string $valor,
    ): void {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => $tabla,
            'operation' => 'created',
            'temp_id' => -70,
            'consulta_id' => $consulta->id,
            'columns' => [$columna => $valor, 'fecha' => now()->toDateString()],
        ]]);

        $this->assertSame([], $response->json('rechazados'), 'la creación se acepta');

        $fila = $this->fila($modelo, $medico, $consulta);
        $this->assertNotNull($fila, 'la fila existe con la clave del legado');
        $this->assertSame((int) $consulta->numhistoria, (int) $fila->historia);
        $this->assertSame((int) $consulta->nroconsulta, (int) $fila->consulta);
        $this->assertSame($valor, (string) $fila->{$columna}, "se guardó `{$columna}`");

        $creado = $this->creado($response, $tabla, -70);
        $this->assertNotNull($creado, 'la creación contesta con su id');
        $this->assertSame((int) $fila->id, $creado['id']);

        // El rastro: el `created` con el id temporal del lote, que protege la fila del reemplazo del
        // escritorio y le permite al teléfono soltar su id provisional.
        $rastro = SyncChange::where('table_name', $tabla)->where('client_temp_id', -70)->first();
        $this->assertNotNull($rastro);
        $this->assertSame('created', $rastro->operation);
        $this->assertSame('mobile', $rastro->source);
        $this->assertSame((int) $fila->id, (int) $rastro->record_id);
    }

    /** @dataProvider estudios */
    public function test_el_reintento_del_mismo_temp_id_no_duplica(
        string $tabla,
        string $modelo,
        string $lectura,
        string $columna,
        string $valor,
    ): void {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $cambio = [
            'table' => $tabla,
            'operation' => 'created',
            'temp_id' => -71,
            'consulta_id' => $consulta->id,
            'columns' => [$columna => $valor],
        ];

        $primera = $this->sync([$cambio]);
        $segunda = $this->sync([$cambio]);

        $this->assertSame(
            $this->creado($primera, $tabla, -71)['id'] ?? null,
            $this->creado($segunda, $tabla, -71)['id'] ?? null,
            'el reintento devuelve el mismo id',
        );
        $this->assertSame(1, $modelo::where('historia', $consulta->numhistoria)
            ->where('consulta', $consulta->nroconsulta)
            ->count(), 'no se duplica la fila del estudio');
    }

    /** @dataProvider estudios */
    public function test_una_columna_fuera_del_mapa_se_descarta(
        string $tabla,
        string $modelo,
        string $lectura,
        string $columna,
        string $valor,
    ): void {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => $tabla,
            'operation' => 'created',
            'temp_id' => -72,
            'consulta_id' => $consulta->id,
            'columns' => [
                $columna => $valor,
                // `puro_texto` existe en las tablas pero NO está en el mapa: lo escribe el escritorio y el
                // teléfono no lo edita. Tampoco hay que confundirlo con `reg_medico`.
                'puro_texto' => 'NO DEBERIA GUARDARSE',
                'reg_medico' => 'otro-registro',
            ],
        ]]);

        $this->assertSame([], $response->json('rechazados'));
        $fila = $this->fila($modelo, $medico, $consulta);
        $this->assertNotNull($fila);
        $this->assertSame($valor, (string) $fila->{$columna});
        $this->assertNotSame('NO DEBERIA GUARDARSE', (string) $fila->puro_texto);
        $this->assertSame($medico->reg_medico, (string) $fila->reg_medico, 'el tenant lo pone el servidor');
    }

    /** @dataProvider estudios */
    public function test_una_consulta_de_otro_medico_se_rechaza(
        string $tabla,
        string $modelo,
        string $lectura,
        string $columna,
        string $valor,
    ): void {
        $ajeno = $this->medico(autenticar: false);
        $consultaAjena = $this->consulta($ajeno);
        $medico = $this->medico();

        $response = $this->sync([[
            'table' => $tabla,
            'operation' => 'created',
            'temp_id' => -73,
            'consulta_id' => $consultaAjena->id,
            'columns' => [$columna => $valor],
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertStringContainsString('no corresponde', $response->json('rechazados.0.motivo'));
        $this->assertSame(0, $modelo::where('reg_medico', $ajeno->reg_medico)->count(), 'no se escribió nada');
        $this->assertSame($medico->reg_medico !== $ajeno->reg_medico, true);
    }

    /** @dataProvider estudios */
    public function test_la_edicion_de_una_columna_del_estudio_aplica_y_deja_rastro(
        string $tabla,
        string $modelo,
        string $lectura,
        string $columna,
        string $valor,
    ): void {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // La fila la carga el escritorio (o una creación anterior) y el teléfono la corrige.
        $fila = $modelo::create([
            'reg_medico' => $medico->reg_medico,
            'historia' => $consulta->numhistoria,
            'consulta' => $consulta->nroconsulta,
            'fecha' => now()->toDateString(),
            $columna => 'VALOR VIEJO',
        ]);

        $response = $this->sync([[
            'table' => $tabla,
            'operation' => 'updated',
            'record_id' => $fila->id,
            'column' => $columna,
            'value' => $valor,
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame([], $response->json('rechazados'));
        $this->assertSame($valor, (string) $fila->fresh()->{$columna});

        $rastro = SyncChange::where('table_name', $tabla)
            ->where('record_id', $fila->id)
            ->where('column_name', $columna)
            ->where('operation', 'updated')
            ->first();
        $this->assertNotNull($rastro, 'la edición deja su rastro por columna');
        $this->assertSame('mobile', $rastro->source);
    }

    /**
     * Los cuatro son **cuatro tablas**: cargarlos en la misma consulta no se pisa entre sí (a diferencia del
     * 2.º y el 3.er trimestre del escritorio, que compartían tabla).
     */
    public function test_los_cuatro_estudios_conviven_en_la_misma_consulta(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $changes = [];
        $tempId = -80;
        foreach (self::estudios() as $estudio) {
            $changes[] = [
                'table' => $estudio['clase'],
                'operation' => 'created',
                'temp_id' => $tempId++,
                'consulta_id' => $consulta->id,
                'columns' => [$estudio['columna'] => $estudio['valor']],
            ];
        }

        $response = $this->sync($changes);
        $this->assertSame([], $response->json('rechazados'), 'las cuatro creaciones se aceptan');

        foreach (self::estudios() as $estudio) {
            $fila = $this->fila($estudio['modelo'], $medico, $consulta);
            $this->assertNotNull($fila, $estudio['clase'] . ' quedó guardado');
            $this->assertSame($estudio['valor'], (string) $fila->{$estudio['columna']});
        }
    }

    /** **Las columnas editables son las del mapa**, no una lista blanca copiada a mano. */
    public function test_las_columnas_editables_de_cada_estudio_son_las_de_su_mapa(): void
    {
        foreach (self::estudios() as $estudio) {
            $esperadas = $estudio['lectura']::columnas();
            $esperadas = array_is_list($esperadas) ? array_values($esperadas) : array_keys($esperadas);

            $this->assertSame($esperadas, SyncAppDataRequest::columnasEditables($estudio['clase']), $estudio['clase']);
            $this->assertArrayHasKey($estudio['clase'], SyncAppDataRequest::MAPAS_DE_LOS_ECOS);
            // Y están en las listas que abren el camino del sync.
            $this->assertContains($estudio['clase'], SyncAppDataRequest::TABLAS_EDITABLES);
            $this->assertContains($estudio['clase'], SyncAppDataRequest::CLINICAL_TABLES);
            $this->assertContains($estudio['clase'], SyncAppDataRequest::SYNC_TABLES);
        }
    }
}
