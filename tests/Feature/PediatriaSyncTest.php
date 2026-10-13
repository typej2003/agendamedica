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
        $email = $reg . '-' . uniqid() . '@example.com';
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
