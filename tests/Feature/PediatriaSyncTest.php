<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use App\Sync\Escritorio\TablasDelSync;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sync del escritorio de **pediatría**: la segunda especialidad (WEB-7.3 y WEB-7.4.1).
 *
 * Lo que se fija acá:
 *  - lo que el API le OFRECE al escritorio depende de la especialidad del médico (y ginecología no
 *    recibe las tablas de pediatría);
 *  - lo que ACEPTA escribir es la unión (un médico con dos especialidades no puede perder datos);
 *  - las tablas que agrega un manifiesto existen en el API (si no, el sync escribiría en el vacío);
 *  - la clave que el escritorio de pediatría llama distinto (`nrohistoria`/`nroconsulta`) se traduce:
 *    sin eso la fila quedaría con la clave en un lado y las pantallas leyendo el otro.
 */
class PediatriaSyncTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';
    private const REG_PED = 'pedia-t-001';
    private const REG_GIN = 'gineco-t-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->medicoDePediatria(self::REG_PED);
        $this->medicoConEspecialidad(self::REG_GIN, 'ginecologia-y-obstetricia');
    }

    private function medicoConEspecialidad(string $reg, ?string $slug): Medico
    {
        $email = $reg . '-' . substr(uniqid(), -6) . '@example.com';
        $user = User::create(['name' => 'Médico ' . $reg, 'email' => $email, 'password' => Hash::make('x')]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => $reg,
            'email' => $email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        if ($slug !== null) {
            $especialidad = Specialty::where('slug', $slug)->firstOrFail();
            $medico->specialties()->attach($especialidad->id);
        }

        return $medico->fresh();
    }

    private function medicoDePediatria(string $reg): Medico
    {
        return $this->medicoConEspecialidad($reg, 'pediatria');
    }

    private function post_(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY]);
    }

    /* ------------------------------------------------------------------ */
    /* Qué se le ofrece a cada escritorio                                  */
    /* ------------------------------------------------------------------ */

    public function test_el_escritorio_de_pediatria_recibe_sus_tablas_y_el_de_ginecologia_no(): void
    {
        $tablasPed = explode(',', (string) $this->post_('cambios/estado', ['reg_medico' => self::REG_PED])
            ->assertOk()->json('tablas'));

        foreach (['paciente_chamo', 'familia_bebe', 'vacunas', 'vacuna_paciente', 'grafica_peso',
            'percentil_nino', 'tipo_vacuna', 'vacunas_compra', 'espiro'] as $tabla) {
            $this->assertContains($tabla, $tablasPed, "pediatría tiene que vigilar {$tabla}");
        }
        // El núcleo sigue primero: la carga inicial crea el paciente y su historia antes que el resto.
        $this->assertSame('pacientes', $tablasPed[0]);

        $tablasGin = explode(',', (string) $this->post_('cambios/estado', ['reg_medico' => self::REG_GIN])
            ->assertOk()->json('tablas'));
        $this->assertContains('pacientes', $tablasGin);
        $this->assertNotContains('paciente_chamo', $tablasGin, 'ginecología no tiene tablas de pediatría');
        $this->assertNotContains('vacunas', $tablasGin);
    }

    public function test_un_medico_con_dos_especialidades_recibe_las_tablas_de_las_dos(): void
    {
        // El pivote `medico_specialty` permite varias: si se tomara sólo la primera, las tablas de la
        // otra no tendrían triggers y esos datos no subirían nunca.
        $medico = $this->medicoConEspecialidad('mixto-t-001', 'ginecologia-y-obstetricia');
        $medico->specialties()->attach(Specialty::where('slug', 'pediatria')->firstOrFail()->id);

        $tablas = app(TablasDelSync::class)->paraMedico($medico->fresh());
        $this->assertContains('paciente_chamo', $tablas, 'las de pediatría');
        $this->assertContains('pre_natal_observaciones', $tablas, 'las de ginecología');
        $this->assertContains('pacientes', $tablas, 'y el núcleo');

        $porHttp = explode(',', (string) $this->post_('cambios/estado', ['reg_medico' => 'mixto-t-001'])
            ->assertOk()->json('tablas'));
        $this->assertContains('paciente_chamo', $porHttp);
        $this->assertContains('pre_natal_observaciones', $porHttp);
    }

    public function test_una_especialidad_sin_manifiesto_sigue_con_la_lista_historica(): void
    {
        $sync = app(TablasDelSync::class);

        $this->assertSame(config('sync_legado.tablas'), $sync->para(null));
        $this->assertSame(config('sync_legado.tablas'), $sync->para('cardiologia'));
        $this->assertContains('paciente_chamo', $sync->para('pediatria'));
        $this->assertNotContains('paciente_chamo', $sync->para('ginecologia'));
    }

    public function test_lo_que_el_api_acepta_es_la_union_de_las_especialidades(): void
    {
        $sync = app(TablasDelSync::class);

        $this->assertTrue($sync->acepta('paciente_chamo'), 'lo de pediatría se acepta');
        $this->assertTrue($sync->acepta('consultas'), 'el núcleo se acepta');
        $this->assertFalse($sync->acepta('users'), 'una tabla del API no');
        $this->assertFalse($sync->acepta('pbcatcol'), 'catálogos de PowerBuilder no');
        $this->assertFalse($sync->acepta('operadores'), 'usuarios del escritorio no');
        $this->assertContains('paciente_chamo', $sync->aceptadas());
    }

    public function test_toda_tabla_que_agrega_una_especialidad_existe_en_el_api(): void
    {
        $porEspecialidad = (array) config('sync_legado.tablas_por_especialidad');

        foreach ($porEspecialidad as $especialidad => $tablas) {
            foreach ($tablas as $tabla) {
                $this->assertTrue(
                    Schema::hasTable($tabla),
                    "el manifiesto de {$especialidad} agrega '{$tabla}', que no existe en el API: hay que migrarla"
                );
            }
        }

        // Lo que agrega pediatría sale de su manifiesto, y son las 56 tablas que el API no tenía.
        $manifiesto = require config_path('especialidades/pediatria.php');
        $this->assertSame(
            array_values($manifiesto['sync']['tablas']),
            array_values($porEspecialidad['pediatria']),
            'la lista de pediatría la manda su manifiesto'
        );
        $this->assertContains('paciente_chamo', $porEspecialidad['pediatria']);
        $this->assertCount(56, $porEspecialidad['pediatria']);

        // Ginecología no agrega nada: sus tablas son la lista histórica.
        $this->assertSame([], $porEspecialidad['ginecologia']);
    }

    /* ------------------------------------------------------------------ */
    /* Carga inicial y cambios                                             */
    /* ------------------------------------------------------------------ */

    public function test_la_carga_inicial_de_pediatria_guarda_sus_tablas(): void
    {
        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG_PED, 'tablas' => [
            'pacientes' => 1, 'paciente_chamo' => 1, 'vacunas' => 1,
        ]])->assertOk();

        $orden = explode(',', (string) $carga->json('orden'));
        $this->assertContains('paciente_chamo', $orden);
        $this->assertContains('vacunas', $orden);

        $this->post_('carga-inicial/lote', [
            'carga_id' => $carga->json('carga_id'), 'tabla' => 'pacientes', 'desde' => 0,
            'filas' => [[
                'numhistoria' => 1, 'cedula' => 'PED-1', 'apellidos' => 'CHAMO', 'nombres' => 'LUIS',
                'nac' => 'V', 'sexo' => 'M',
            ]],
        ])->assertOk();

        $this->post_('carga-inicial/lote', [
            'carga_id' => $carga->json('carga_id'), 'tabla' => 'paciente_chamo', 'desde' => 0,
            'filas' => [[
                'familia' => 1, 'historia' => 1, 'nombre' => 'LUIS CHAMO', 'sexo' => 'M',
                'f_nacimiento' => '2020-05-04', 'pediatrico' => 'S',
            ]],
        ])->assertOk();

        $this->post_('carga-inicial/lote', [
            'carga_id' => $carga->json('carga_id'), 'tabla' => 'vacunas', 'desde' => 0,
            'filas' => [['codi_vacuna' => 'V001', 'vacuna' => 'BCG', 'edad' => 0]],
        ])->assertOk();

        $this->post_('carga-inicial/finalizar', ['carga_id' => $carga->json('carga_id')])->assertOk();

        $this->assertSame('LUIS CHAMO', DB::table('paciente_chamo')->where('reg_medico', self::REG_PED)->value('nombre'));
        $this->assertSame('S', DB::table('paciente_chamo')->where('reg_medico', self::REG_PED)->value('pediatrico'));
        $this->assertSame('BCG', DB::table('vacunas')->where('reg_medico', self::REG_PED)->value('vacuna'));
    }

    public function test_los_cambios_de_pediatria_traducen_la_clave_del_escritorio(): void
    {
        $this->cargaMinimaDePediatria();

        // El examen físico por regiones: la clave del escritorio es `nrohistoria` + `nroconsulta`.
        $respuesta = $this->post_('cambios/subir', ['reg_medico' => self::REG_PED, 'cambios' => [[
            'id' => 7, 'tabla' => 'examen_fisico_nuevo', 'op' => 'I', 'fecha' => '2026-05-04 12:00:00',
            'clave' => ['nrohistoria' => 1, 'nroconsulta' => 1],
            'fila' => ['nrohistoria' => 1, 'nroconsulta' => 1, 'cabeza' => 'normocéfalo', 'torax' => 'normal'],
        ]]])->assertOk();

        $this->assertSame('7', (string) $respuesta->json('confirmados'));

        $fila = DB::table('examen_fisico_nuevo')->where('reg_medico', self::REG_PED)->first();
        $this->assertNotNull($fila, 'la fila tiene que haber entrado');
        $this->assertSame(1, (int) $fila->historia, 'la historia se guarda en la columna del API');
        $this->assertSame(1, (int) $fila->consulta);
        $this->assertSame('normocéfalo', $fila->cabeza);
        $this->assertFalse(Schema::hasColumn('examen_fisico_nuevo', 'nrohistoria'), 'no se agrega una columna de más');
    }

    public function test_las_equivalencias_de_columna_prefieren_el_nombre_del_api(): void
    {
        $tablas = app(TablasLegado::class);
        $ignoradas = [];

        $traducida = $tablas->traducirColumnas('examen_fisico_nuevo', [
            'nrohistoria' => 5, 'nroconsulta' => 3, 'cabeza' => 'x',
        ]);
        $this->assertSame(['historia' => 5, 'consulta' => 3, 'cabeza' => 'x'], $traducida);

        $this->assertSame(
            ['historia' => 5, 'consulta' => 3, 'cabeza' => 'x'],
            $tablas->filtrar('examen_fisico_nuevo', ['nrohistoria' => 5, 'nroconsulta' => 3, 'cabeza' => 'x'], $ignoradas)
        );
        $this->assertSame([], $ignoradas);

        // Si vinieran las dos formas, gana la del API.
        $this->assertSame(
            ['historia' => 9],
            $tablas->traducirColumnas('examen_fisico_nuevo', ['nrohistoria' => 5, 'historia' => 9])
        );
    }

    public function test_el_sync_no_acepta_una_tabla_de_otra_cosa(): void
    {
        $this->cargaMinimaDePediatria();

        $respuesta = $this->post_('cambios/subir', ['reg_medico' => self::REG_PED, 'cambios' => [[
            'tabla' => 'users', 'op' => 'I', 'fecha' => '2026-05-04 12:00:00',
            'clave' => ['id' => 1], 'fila' => ['id' => 1, 'name' => 'colado'],
        ]]])->assertOk();

        $this->assertSame('', (string) $respuesta->json('confirmados'));
        $this->assertStringContainsString('tabla no permitida', (string) $respuesta->json('rechazados'));
        $this->assertSame(0, DB::table('users')->where('name', 'colado')->count());
    }

    /* ------------------------------------------------------------------ */
    /* El paciente pediátrico viene de `paciente_chamo` (2026-10-14)       */
    /* ------------------------------------------------------------------ */

    /**
     * **Pediatría no usa `pacientes`**: su ABM de pacientes es `paciente_chamo` (el chamo, con su familia
     * y su representante) y su `pacientes` está vacía en las bases reales. Si la carga no arma el paciente
     * y su historia desde ahí, el médico queda sin fichas: la web no lista a nadie y el app abre en blanco
     * (pasó de verdad: 1,19 M de filas cargadas y 0 pacientes).
     */
    public function test_la_carga_de_pediatria_arma_el_paciente_y_su_historia_desde_el_chamo(): void
    {
        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG_PED, 'tablas' => [
            'paciente_chamo' => 1,
        ]])->assertOk();

        // El escritorio manda SU clave primaria: `historia`.
        $this->post_('carga-inicial/lote', [
            'carga_id' => $carga->json('carga_id'), 'tabla' => 'paciente_chamo', 'desde' => 0,
            'filas' => [[
                'familia' => 7, 'historia' => 1, 'nombre' => 'LUIS CHAMO', 'sexo' => 'M',
                'f_nacimiento' => '2020-05-04', 'celular' => '0414-1234567', 'segu_empre' => 'SE',
                'alergia' => 'penicilina',
            ]],
        ])->assertOk();
        $this->post_('carga-inicial/finalizar', ['carga_id' => $carga->json('carga_id')])->assertOk();

        // 1) La historia y su relación con el médico.
        $historia = DB::table('historias')->where('reg_medico', self::REG_PED)->first();
        $this->assertNotNull($historia, 'se tiene que crear la historia');
        $this->assertSame('1', (string) $historia->clave_escritorio, 'la clave es el número del escritorio');
        $this->assertSame('1', (string) $historia->numhistoria);

        $vinculo = DB::table('medico_pacientes')->where('medico_id', $historia->medico_id)->first();
        $this->assertNotNull($vinculo, 'y la relación con el médico');
        $this->assertSame($historia->paciente_id, $vinculo->paciente_id);
        $this->assertSame('1', (string) $vinculo->numhistoria);

        // 2) El paciente, con las columnas mapeadas del manifiesto (`nombre`→`nombres`,
        //    `celular`→`telefono`, `f_nacimiento`→`fnacimiento`, `segu_empre`→`codesegemp`).
        $paciente = DB::table('pacientes')->where('id', $historia->paciente_id)->first();
        $this->assertSame('LUIS CHAMO', $paciente->nombres);
        $this->assertSame('M', $paciente->sexo);
        $this->assertStringStartsWith('2020-05-04', (string) $paciente->fnacimiento);
        $this->assertSame('0414-1234567', $paciente->telefono);
        $this->assertSame('SE', $paciente->codesegemp);
        $this->assertNull($paciente->apellidos, 'el chamo no trae apellidos por separado: no se inventan');

        // 3) Y el espejo: la tabla propia del escritorio se sigue guardando tal cual (sus columnas que no
        //    existen en `pacientes` son las que usa el app de pediatría).
        $chamo = DB::table('paciente_chamo')->where('reg_medico', self::REG_PED)->first();
        $this->assertSame('penicilina', $chamo->alergia);
        $this->assertSame('7', (string) $chamo->familia);
    }

    public function test_un_cambio_de_chamo_crea_y_despues_actualiza_el_paciente(): void
    {
        $this->cargaMinimaDePediatria();

        // Alta desde el escritorio: la clave es `{historia}` (la primaria de `paciente_chamo`).
        $respuesta = $this->post_('cambios/subir', ['reg_medico' => self::REG_PED, 'cambios' => [[
            'id' => 21, 'tabla' => 'paciente_chamo', 'op' => 'I', 'fecha' => '2026-05-05 09:00:00',
            'clave' => ['historia' => 9],
            'fila' => ['familia' => 3, 'historia' => 9, 'nombre' => 'NUEVO CHAMO', 'sexo' => 'F',
                'f_nacimiento' => '2021-01-02', 'alergia' => 'ninguna'],
        ]]])->assertOk();
        $this->assertSame(['21'], $this->confirmados($respuesta), (string) $respuesta->json('rechazados'));

        $historia = DB::table('historias')->where('reg_medico', self::REG_PED)->where('clave_escritorio', '9')->first();
        $this->assertNotNull($historia, 'el paciente nuevo tiene su historia');
        $this->assertSame('NUEVO CHAMO', DB::table('pacientes')->where('id', $historia->paciente_id)->value('nombres'));
        $this->assertStringStartsWith('2021-01-02', (string) DB::table('pacientes')->where('id', $historia->paciente_id)->value('fnacimiento'));

        // Edición: cambia el nombre y la alergia.
        $this->post_('cambios/subir', ['reg_medico' => self::REG_PED, 'cambios' => [[
            'id' => 22, 'tabla' => 'paciente_chamo', 'op' => 'U', 'fecha' => '2026-05-06 09:00:00',
            'clave' => ['historia' => 9],
            'fila' => ['familia' => 3, 'historia' => 9, 'nombre' => 'NUEVO CHAMO DOS', 'sexo' => 'F',
                'alergia' => 'penicilina'],
        ]]])->assertOk();

        $this->assertSame('NUEVO CHAMO DOS', DB::table('pacientes')->where('id', $historia->paciente_id)->value('nombres'));
        $this->assertSame('penicilina', DB::table('paciente_chamo')->where('reg_medico', self::REG_PED)->where('historia', 9)->value('alergia'));
        // Guardar un cambio no duplica el paciente ni la historia.
        $this->assertSame(1, DB::table('historias')->where('reg_medico', self::REG_PED)->where('clave_escritorio', '9')->count());
    }

    public function test_un_lote_de_pacientes_sin_el_numero_de_historia_se_rechaza(): void
    {
        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG_PED, 'tablas' => [
            'paciente_chamo' => 1,
        ]])->assertOk();

        // Sin la columna de la historia no se puede armar nada. Antes esto respondía "recibido", la carga
        // terminaba "Completa" y el médico quedaba con 0 pacientes sin un solo error visible.
        $this->post_('carga-inicial/lote', [
            'carga_id' => $carga->json('carga_id'), 'tabla' => 'paciente_chamo', 'desde' => 0,
            'filas' => [['familia' => 1, 'nombre' => 'SIN HISTORIA']],
        ])->assertStatus(500)->assertJsonPath('error', 'error_guardando');

        $this->assertSame(0, DB::table('paciente_chamo')->where('reg_medico', self::REG_PED)->count(), 'no quedó nada a medias');
    }

    /**
     * El comando de reparación: cuando la tabla de pacientes ya está en el API (una carga hecha antes de
     * que existiera el mapeo) arma las historias a partir de ella, sin pedirle nada al escritorio.
     */
    public function test_el_comando_arma_los_pacientes_desde_la_tabla_que_ya_esta_en_el_api(): void
    {
        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG_PED, 'tablas' => [
            'paciente_chamo' => 0,
        ]])->assertOk();
        $this->post_('carga-inicial/finalizar', ['carga_id' => $carga->json('carga_id')])->assertOk();

        // El espejo, como lo dejó aquella carga: filas sin historia en el API.
        foreach ([['historia' => 3, 'nombre' => 'UNO CHAMO'], ['historia' => 4, 'nombre' => 'DOS CHAMO']] as $fila) {
            DB::table('paciente_chamo')->insert([
                'reg_medico' => self::REG_PED, 'familia' => 1, 'historia' => $fila['historia'],
                'nombre' => $fila['nombre'], 'sexo' => 'M', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertSame(0, DB::table('historias')->where('reg_medico', self::REG_PED)->count());

        $this->artisan('sync:pacientes-del-escritorio', ['reg_medico' => self::REG_PED, '--simular' => true])
            ->assertSuccessful();
        $this->assertSame(0, DB::table('historias')->where('reg_medico', self::REG_PED)->count(), 'la simulación no escribe');

        $this->artisan('sync:pacientes-del-escritorio', ['reg_medico' => self::REG_PED])->assertSuccessful();

        $this->assertSame(2, DB::table('historias')->where('reg_medico', self::REG_PED)->count());
        $this->assertSame('UNO CHAMO',
            DB::table('pacientes')->join('historias', 'historias.paciente_id', '=', 'pacientes.id')
                ->where('historias.clave_escritorio', '3')->value('pacientes.nombres'));
        $this->assertSame('4', (string) DB::table('historias')->where('reg_medico', self::REG_PED)
            ->where('clave_escritorio', '4')->value('numhistoria'));

        // Idempotente: correrlo de nuevo no duplica nada.
        $this->artisan('sync:pacientes-del-escritorio', ['reg_medico' => self::REG_PED])->assertSuccessful();
        $this->assertSame(2, DB::table('historias')->where('reg_medico', self::REG_PED)->count());

        // Y los dos pacientes quedan vinculados al médico: el pivote es lo que el app usa para listarlos.
        $medico = Medico::where('reg_medico', self::REG_PED)->firstOrFail();
        $this->assertSame(2, DB::table('medico_pacientes')->where('medico_id', $medico->id)->count());
    }

    private function confirmados($respuesta): array
    {
        return array_values(array_filter(explode(',', (string) $respuesta->json('confirmados'))));
    }

    /* ------------------------------------------------------------------ */
    /* La especialidad se vincula sola en la primera sincronización        */
    /* ------------------------------------------------------------------ */

    /**
     * El técnico no tiene que asignar la especialidad a mano: el escritorio anuncia **todas** sus tablas
     * antes de subir nada, y las distintivas de cada especialidad están en su manifiesto. Sin esto, un
     * médico nuevo recibe la lista histórica (la de ginecología) y en la web entra a un consultorio sin
     * módulos.
     */
    public function test_la_primera_sincronizacion_le_vincula_la_especialidad_al_medico(): void
    {
        $medico = $this->medicoConEspecialidad('nuevo-t-001', null);
        $this->assertSame([], app(TablasDelSync::class)->especialidadesDe($medico));

        $respuesta = $this->post_('carga-inicial/iniciar', ['reg_medico' => 'nuevo-t-001', 'tablas' => [
            'pacientes' => 0, 'paciente_chamo' => 2, 'vacunas' => 1, 'percentil_nino' => 5,
        ]])->assertOk();

        $this->assertSame('pediatria', (string) $respuesta->json('especialidad'));
        $this->assertContains('pediatria', app(TablasDelSync::class)->especialidadesDe($medico->fresh()));

        // A partir de ahí el escritorio recibe SU lista (y la web sabe qué módulos mostrar).
        $tablas = explode(',', (string) $this->post_('cambios/estado', ['reg_medico' => 'nuevo-t-001'])
            ->assertOk()->json('tablas'));
        $this->assertContains('paciente_chamo', $tablas);
    }

    public function test_sin_evidencia_no_se_le_asigna_ninguna_especialidad(): void
    {
        $medico = $this->medicoConEspecialidad('core-t-001', null);

        // Sólo tablas del núcleo: no hay de dónde deducir, y adivinar mal es peor que no adivinar (la
        // especialidad decide los módulos de la web clínica).
        $respuesta = $this->post_('carga-inicial/iniciar', ['reg_medico' => 'core-t-001', 'tablas' => [
            'pacientes' => 1, 'consultas' => 1, 'cola' => 1,
        ]])->assertOk();

        $this->assertSame('', (string) $respuesta->json('especialidad'));
        $this->assertSame([], app(TablasDelSync::class)->especialidadesDe($medico->fresh()));
    }

    /**
     * Deja a `REG_PED` con la carga inicial completa y un paciente con su consulta (historias y
     * consultas por los endpoints reales: el escritorio manda SUS números).
     */
    private function cargaMinimaDePediatria(int $historia = 1, string $cedula = 'PED-2'): void
    {
        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG_PED, 'tablas' => [
            'pacientes' => 1, 'consultas' => 1,
        ]])->assertOk();

        $this->post_('carga-inicial/lote', [
            'carga_id' => $carga->json('carga_id'), 'tabla' => 'pacientes', 'desde' => 0,
            'filas' => [[
                'numhistoria' => $historia, 'cedula' => $cedula, 'apellidos' => 'CHAMO',
                'nombres' => 'ANA', 'nac' => 'V', 'sexo' => 'F',
            ]],
        ])->assertOk();

        $this->post_('carga-inicial/lote', [
            'carga_id' => $carga->json('carga_id'), 'tabla' => 'consultas', 'desde' => 0,
            'filas' => [['numhistoria' => $historia, 'nroconsulta' => 1, 'fecha' => '2026-05-04', 'peso' => 12.5]],
        ])->assertOk();

        $this->post_('carga-inicial/finalizar', ['carga_id' => $carga->json('carga_id')])->assertOk();
    }
}
