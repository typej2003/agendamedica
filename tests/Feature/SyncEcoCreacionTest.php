<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoSegundo;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Http\Requests\SyncAppDataRequest;
use App\Models\Consulta;
use App\Models\EcoObstetrico as ModeloDelPrimero;
use App\Models\EcoObstetricoSegundo as ModeloDelSegundo;
use App\Models\EcoObstetricoTercer as ModeloDelTercero;
use App\Models\EcoPelvico;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **EC-A3 de PLAN-MOVIL.md: los cuatro estudios ecográficos se cargan desde el teléfono.**
 *
 * Es la otra mitad de «ecos en el bolsillo»: EC-A1 y EC-A2 le dieron al app los mapas y la lectura, y acá el
 * teléfono **escribe** —el alta de una fila que todavía no existe en el servidor y la corrección de una
 * columna por el camino genérico de A1—. Lo que se fija:
 *
 *  1. Que crear cada estudio deje la fila **en su tabla**, con **la clave del legado** —`numhistoria` +
 *     `nroconsulta` en el pélvico, `historia` + `consulta` en los tres obstétricos— y los valores cargados.
 *  2. Que una consulta creada en el **mismo lote** (`consulta_temp_id`) resuelva a sus números reales.
 *  3. Que el reintento con el mismo `temp_id` **no duplique** y devuelva **el mismo id**.
 *  4. Que una consulta de **otro** médico se rechace sin escribir nada ni trabar el lote.
 *  5. Que una columna **fuera del mapa** se descarte y el resto se guarde (creando y editando).
 *  6. Que la edición de una columna propia por `updated` (camino de A1) aplique y deje su rastro
 *     `source = mobile`.
 *  7. Que los cuatro estudios **no se pisen entre sí**: son cuatro tablas, y el pélvico y el 3.º pueden
 *     convivir en la misma consulta.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncEcoCreacionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-eco-creacion-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-eco-' . uniqid() . '@example.com',
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

    /**
     * La fila que el estudio dejó, buscada por la clave del legado de su tabla y no por el id de la consulta.
     *
     * @param  class-string<Model>  $modelo
     */
    private function fila(string $modelo, Medico $medico, Consulta $consulta, string $columnaHistoria, string $columnaConsulta): ?Model
    {
        return $modelo::where('reg_medico', $medico->reg_medico)
            ->where($columnaHistoria, (int) $consulta->numhistoria)
            ->where($columnaConsulta, (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * Los cuatro estudios, con lo mínimo que los distingue: su tabla, su modelo, **los nombres de sus dos
     * columnas de clave** (la trampa del paso: el pélvico usa `numhistoria`/`nroconsulta` y los obstétricos
     * `historia`/`consulta`) y un par de columnas de su mapa para cargar.
     *
     * @return array<string,array{0:string,1:class-string<Model>,2:string,3:string,4:array<string,string>,5:array<string,string>}>
     */
    public static function estudios(): array
    {
        return [
            'pélvico' => [
                'eco_pelvico', EcoPelvico::class, 'numhistoria', 'nroconsulta',
                ['cuerpo' => '85', 'sugerencias' => 'UTERO GRAVIDICO', 'observacion' => 'SIN HALLAZGOS'],
                ['cuerpo' => '85', 'sugerencias' => 'UTERO GRAVIDICO'],
            ],
            'obstétrico del 1.er trimestre' => [
                'eco_obstetrico', ModeloDelPrimero::class, 'historia', 'consulta',
                ['cuerpo_utero' => '1', 'lcc' => '12.5', 'observacion' => 'EMBARAZO DE 8 SEMANAS'],
                ['cuerpo_utero' => '1', 'lcc' => '12.5'],
            ],
            'obstétrico del 2.º trimestre' => [
                'eco_obstetrico_tercer_2', ModeloDelSegundo::class, 'historia', 'consulta',
                ['bio_ca_bp' => '45', 'fun_dp_grado' => 'II', 'conclusion' => 'FETO UNICO VIVO'],
                ['bio_ca_bp' => '45', 'conclusion' => 'FETO UNICO VIVO'],
            ],
            'obstétrico del 3.er trimestre' => [
                'eco_obstetrico_tercer', ModeloDelTercero::class, 'historia', 'consulta',
                ['bio_peso_fetal' => '2500', 'presentacion' => 'CEFALICA', 'conclusion' => 'FETO UNICO VIVO A TERMINO'],
                ['bio_peso_fetal' => '2500', 'conclusion' => 'FETO UNICO VIVO A TERMINO'],
            ],
        ];
    }

    /** @dataProvider estudios */
    public function test_crear_un_estudio_deja_la_fila_en_su_tabla_con_la_clave_del_legado(
        string $tabla,
        string $modelo,
        string $columnaHistoria,
        string $columnaConsulta,
        array $columnas,
        array $comprobaciones
    ): void {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => $tabla,
            'operation' => 'created',
            'temp_id' => -50,
            'consulta_id' => $consulta->id,
            'columns' => $columnas,
        ]]);

        $this->assertSame([], $response->json('rechazados'), 'la creación entra');

        $fila = $this->fila($modelo, $medico, $consulta, $columnaHistoria, $columnaConsulta);
        $this->assertNotNull($fila, "la fila de {$tabla} se creó");
        $this->assertSame((int) $consulta->numhistoria, (int) $fila->{$columnaHistoria},
            "`{$columnaHistoria}` es el número de historia");
        $this->assertSame((int) $consulta->nroconsulta, (int) $fila->{$columnaConsulta},
            "`{$columnaConsulta}` es el NÚMERO de la consulta");
        $this->assertNotSame((int) $consulta->id, (int) $fila->{$columnaConsulta},
            'el número de la consulta no es su id (trampa del §7.4)');
        $this->assertSame($medico->reg_medico, $fila->reg_medico);

        foreach ($comprobaciones as $columna => $valor) {
            $this->assertSame($valor, (string) $fila->{$columna}, "se guardó `{$columna}`");
        }

        // Lo que el teléfono guarda como `remote_id`.
        $creado = $this->creado($response, $tabla, -50);
        $this->assertNotNull($creado, 'la creación contesta con su id');
        $this->assertSame((int) $fila->id, $creado['id']);

        // El rastro de la creación, con el id temporal del lote.
        $rastro = SyncChange::where('table_name', $tabla)->where('client_temp_id', -50)->first();
        $this->assertNotNull($rastro);
        $this->assertSame('created', $rastro->operation);
        $this->assertSame('mobile', $rastro->source);
        $this->assertSame($medico->reg_medico, $rastro->reg_medico);
    }

    public function test_una_consulta_creada_en_el_mismo_lote_resuelve_a_sus_numeros_reales(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // Desordenados a propósito, como los manda un teléfono sin conexión: el servidor los reordena.
        $response = $this->sync([
            [
                'table' => 'eco_obstetrico',
                'operation' => 'created',
                'temp_id' => -51,
                'consulta_temp_id' => -22,
                'columns' => ['lcc' => '15.5', 'observacion' => 'EMBARAZO DE 9 SEMANAS'],
            ],
            [
                'table' => 'consultas',
                'operation' => 'created',
                'temp_id' => -22,
                'numhistoria' => $consulta->numhistoria,
                'fecha' => now()->toDateString(),
            ],
        ]);

        $this->assertSame([], $response->json('rechazados'));

        $creada = $this->creado($response, 'consultas', -22);
        $this->assertNotNull($creada, 'la consulta del lote se creó primero');

        $fila = ModeloDelPrimero::where('reg_medico', $medico->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $creada['nroconsulta'])
            ->first();

        $this->assertNotNull($fila, 'el estudio cuelga de la consulta del mismo lote');
        $this->assertSame('15.5', (string) $fila->lcc);
        // La consulta del lote es la 2 de esa historia: la fila tiene que colgar de ese número y no del 1.
        $this->assertSame(2, (int) $fila->consulta);
        $this->assertSame((int) $fila->id, $this->creado($response, 'eco_obstetrico', -51)['id']);
    }

    public function test_reintentar_la_misma_creacion_no_duplica_y_devuelve_el_mismo_id(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $cambio = [[
            'table' => 'eco_obstetrico_tercer',
            'operation' => 'created',
            'temp_id' => -52,
            'consulta_id' => $consulta->id,
            'columns' => ['bio_peso_fetal' => '2800', 'conclusion' => 'FETO UNICO VIVO'],
        ]];

        $primera = $this->sync($cambio);
        $segunda = $this->sync($cambio);

        $this->assertSame(
            $this->creado($primera, 'eco_obstetrico_tercer', -52),
            $this->creado($segunda, 'eco_obstetrico_tercer', -52),
            'el reintento contesta lo mismo',
        );
        $this->assertSame(1, ModeloDelTercero::where('reg_medico', $medico->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->count(), 'la fila no se duplica');
        $this->assertSame('2800', (string) $this
            ->fila(ModeloDelTercero::class, $medico, $consulta, 'historia', 'consulta')->bio_peso_fetal);
    }

    public function test_una_consulta_de_otro_medico_se_rechaza_y_no_escribe_nada(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajena = $this->consulta($otro);

        $this->medico();

        $response = $this->sync([[
            'table' => 'eco_pelvico',
            'operation' => 'created',
            'temp_id' => -53,
            'consulta_id' => $ajena->id,
            'columns' => ['cuerpo' => '90'],
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame('eco_pelvico', $response->json('rechazados.0.table'));
        $this->assertSame(-53, $response->json('rechazados.0.temp_id'));
        $this->assertSame(
            'El estudio no corresponde a una consulta de este médico.',
            $response->json('rechazados.0.motivo'),
        );

        $this->assertSame(0, EcoPelvico::where('numhistoria', (int) $ajena->numhistoria)->count(),
            'no se escribió ninguna fila con la clave de la consulta ajena');
        $this->assertSame(0, SyncChange::where('table_name', 'eco_pelvico')
            ->where('client_temp_id', -53)
            ->count(), 'ni rastro de la creación rechazada');
    }

    /**
     * `puro_texto` es una columna real de `eco_pelvico` que **no** está en el mapa (el informe en texto libre
     * del escritorio); `resumen` ni siquiera existe. Las dos se descartan sin trabar el lote y el resto de las
     * columnas se guarda.
     */
    public function test_una_columna_fuera_del_mapa_se_descarta_y_el_resto_se_guarda(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'eco_pelvico',
            'operation' => 'created',
            'temp_id' => -54,
            'consulta_id' => $consulta->id,
            'columns' => [
                'resumen' => 'NO ES UNA COLUMNA',
                'puro_texto' => 'INFORME EN TEXTO LIBRE DEL ESCRITORIO',
                'cuerpo' => '92',
                'sugerencias' => 'UTERO EN AVF',
            ],
        ]]);

        $this->assertSame([], $response->json('rechazados'), 'la creación igual entra');

        $fila = $this->fila(EcoPelvico::class, $medico, $consulta, 'numhistoria', 'nroconsulta');
        $this->assertNotNull($fila);
        $this->assertNull($fila->puro_texto, 'el texto libre del escritorio no se escribe');
        $this->assertSame('92', (string) $fila->cuerpo);
        $this->assertSame('UTERO EN AVF', $fila->sugerencias, 'la conclusión del mapa sí');
    }

    public function test_editar_una_columna_propia_por_updated_aplica_y_deja_rastro_mobile(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sync([[
            'table' => 'eco_pelvico',
            'operation' => 'created',
            'temp_id' => -55,
            'consulta_id' => $consulta->id,
            'columns' => ['cuerpo' => '85', 'sugerencias' => 'UTERO GRAVIDICO'],
        ]]);

        $fila = $this->fila(EcoPelvico::class, $medico, $consulta, 'numhistoria', 'nroconsulta');
        $this->assertNotNull($fila);

        // El camino de A1: una columna, con su `record_id` (el id real que devolvió la creación) y el valor.
        $this->sync([
            [
                'table' => 'eco_pelvico',
                'operation' => 'updated',
                'record_id' => $fila->id,
                'column' => 'cuerpo',
                'value' => '90',
                'occurred_at' => now()->toIso8601String(),
            ],
            [
                // Fuera del mapa: se descarta sin trabar el lote.
                'table' => 'eco_pelvico',
                'operation' => 'updated',
                'record_id' => $fila->id,
                'column' => 'puro_texto',
                'value' => 'NO SE PUEDE ESCRIBIR DESDE EL TELEFONO',
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $fila->refresh();
        $this->assertSame('90', (string) $fila->cuerpo, 'la columna del mapa se aplicó');
        $this->assertNull($fila->puro_texto, 'la que no está en el mapa se descartó');

        $rastro = SyncChange::where('table_name', 'eco_pelvico')
            ->where('record_id', $fila->id)
            ->where('operation', 'updated')
            ->where('column_name', 'cuerpo')
            ->first();
        $this->assertNotNull($rastro, 'la edición deja su rastro por columna');
        $this->assertSame('mobile', $rastro->source);
        $this->assertSame($medico->reg_medico, $rastro->reg_medico);
        $this->assertSame('90', $rastro->value);

        $this->assertSame(0, SyncChange::where('table_name', 'eco_pelvico')
            ->where('record_id', $fila->id)
            ->where('column_name', 'puro_texto')
            ->count(), 'la columna descartada no deja rastro');
    }

    public function test_el_pelvico_y_el_tercero_no_se_pisan_porque_son_tablas_distintas(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([
            [
                'table' => 'eco_pelvico',
                'operation' => 'created',
                'temp_id' => -56,
                'consulta_id' => $consulta->id,
                'columns' => ['cuerpo' => '70', 'sugerencias' => 'UTERO EN RVF'],
            ],
            [
                'table' => 'eco_obstetrico_tercer',
                'operation' => 'created',
                'temp_id' => -57,
                'consulta_id' => $consulta->id,
                'columns' => ['bio_peso_fetal' => '3100', 'conclusion' => 'FETO UNICO VIVO'],
            ],
        ]);

        $this->assertSame([], $response->json('rechazados'));

        $pelvico = $this->fila(EcoPelvico::class, $medico, $consulta, 'numhistoria', 'nroconsulta');
        $tercero = $this->fila(ModeloDelTercero::class, $medico, $consulta, 'historia', 'consulta');

        $this->assertNotNull($pelvico, 'el pélvico se guardó en su tabla');
        $this->assertNotNull($tercero, 'el del 3.º se guardó en la suya');
        $this->assertSame('70', (string) $pelvico->cuerpo);
        $this->assertSame('UTERO EN RVF', $pelvico->sugerencias, 'el pélvico conserva lo suyo');
        $this->assertSame('3100', (string) $tercero->bio_peso_fetal);
        $this->assertSame('FETO UNICO VIVO', $tercero->conclusion, 'el del 3.º conserva lo suyo');
        // Son dos tablas y dos filas: cada una con su propio `id` (los `id` de tablas distintas se repiten).
        $this->assertInstanceOf(EcoPelvico::class, $pelvico);
        $this->assertInstanceOf(ModeloDelTercero::class, $tercero);
    }

    /**
     * El accesor es la fuente de las columnas editables: las del **mapa** de cada estudio, sin una lista
     * paralela que se desincronice. Se fija el caso que importa (una columna real que no está en el mapa) y el
     * tamaño de los cuatro mapas.
     */
    public function test_las_columnas_editables_de_los_ecos_son_las_del_mapa(): void
    {
        $this->assertCount(35, SyncAppDataRequest::columnasEditables('eco_pelvico'));
        $this->assertCount(36, SyncAppDataRequest::columnasEditables('eco_obstetrico'));
        $this->assertCount(55, SyncAppDataRequest::columnasEditables('eco_obstetrico_tercer_2'));
        $this->assertCount(55, SyncAppDataRequest::columnasEditables('eco_obstetrico_tercer'));

        $this->assertEqualsCanonicalizing(
            array_keys(EcoGinecologico::columnas()),
            SyncAppDataRequest::columnasEditables('eco_pelvico'),
        );
        $this->assertSame(
            EcoObstetrico::columnas(),
            SyncAppDataRequest::columnasEditables('eco_obstetrico'),
        );
        $this->assertSame(
            EcoObstetricoSegundo::columnas(),
            SyncAppDataRequest::columnasEditables('eco_obstetrico_tercer_2'),
        );
        $this->assertSame(
            EcoObstetricoTercero::columnas(),
            SyncAppDataRequest::columnasEditables('eco_obstetrico_tercer'),
        );

        $this->assertNotContains('puro_texto', SyncAppDataRequest::columnasEditables('eco_pelvico'));
        $this->assertContains('cuerpo', SyncAppDataRequest::columnasEditables('eco_pelvico'));
    }

    /**
     * Un estudio **sin ningún dato** no es un documento: la Action de la web lo rechaza y el teléfono recibe el
     * motivo en vez de una fila vacía (y el resto del lote sigue).
     */
    public function test_un_estudio_sin_datos_se_rechaza_sin_crear_la_fila(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $response = $this->sync([[
            'table' => 'eco_obstetrico',
            'operation' => 'created',
            'temp_id' => -58,
            'consulta_id' => $consulta->id,
            'columns' => ['puro_texto' => 'SOLO UNA COLUMNA QUE NO ES DEL MAPA'],
        ]]);

        $this->assertSame([], $response->json('creados'));
        $this->assertCount(1, $response->json('rechazados'));
        $this->assertSame(
            'El eco necesita algún dato: cargá lo que haya dado el estudio.',
            $response->json('rechazados.0.motivo'),
        );
        $this->assertSame(0, ModeloDelPrimero::where('reg_medico', $medico->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->count(), 'no se creó una fila vacía');
    }
}
