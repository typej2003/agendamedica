<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\SyncChange;
use App\Models\SyncCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sincronización incremental del escritorio, subida (Fase 2.A). Ver bridge/DISENO-FASE-2.md.
 *
 * Cada test parte de un médico con la carga inicial COMPLETA (hecha por los endpoints reales):
 * historias 1 y 2, consulta 1|1 y la cita del 2026-10-01 08:00.
 */
class CambiosEscritorioTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';
    private const REG = 'cambios-t-001';

    private Medico $medico;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create(['name' => 'Médico cambios', 'email' => 'cambios-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('x')]);
        $this->medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => 'Cambios', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => self::REG,
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => self::REG]);

        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG, 'tablas' => [
            'pacientes' => 2, 'consultas' => 1, 'cola' => 1, 'antece_paciente' => 1,
        ]])->assertOk()->json('carga_id');
        $lote = fn ($tabla, $filas) => $this->post_('carga-inicial/lote', ['carga_id' => $carga, 'tabla' => $tabla, 'desde' => 0, 'filas' => $filas])->assertOk();
        $lote('pacientes', [$this->paciente(1, 'CAMB-1'), $this->paciente(2, 'CAMB-2')]);
        $lote('consultas', [['numhistoria' => 1, 'nroconsulta' => 1, 'fecha' => '2026-01-10', 'enfermedadactual' => 'X']]);
        $lote('cola', [['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'motivo' => 'CONTROL', 'hora_fin' => '08:20:00']]);
        $lote('antece_paciente', [['numhistoria' => 1, 'codeantecedente' => 'A1', 'detalles' => 'original']]);
        $this->post_('carga-inicial/finalizar', ['carga_id' => $carga])->assertOk();
    }

    private function post_(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY]);
    }

    private function subir(array $cambios)
    {
        return $this->post_('cambios/subir', ['reg_medico' => self::REG, 'cambios' => $cambios])->assertOk();
    }

    private function paciente(int $historia, ?string $cedula, string $nombre = 'ANA'): array
    {
        return ['numhistoria' => $historia, 'cedula' => $cedula, 'apellidos' => 'PRUEBA', 'nombres' => $nombre, 'nac' => 'V', 'sexo' => 'F'];
    }

    private function confirmados($respuesta): array
    {
        return array_filter(explode(',', (string) $respuesta->json('confirmados')));
    }

    /* ------------------------------------------------------------------ */

    public function test_la_carga_deja_la_clave_del_escritorio_en_historias_consultas_y_citas(): void
    {
        $this->assertSame('1', Historia::where('reg_medico', self::REG)->where('numhistoria', '1')->value('clave_escritorio'));
        $this->assertSame('1|1', DB::table('consultas')->where('reg_medico', self::REG)->value('clave_escritorio'));
        $this->assertSame('2026-10-01|08:00:00', DB::table('cola')->where('reg_medico', self::REG)->value('clave_escritorio'));
    }

    public function test_estado_dice_si_la_carga_esta_completa_y_que_tablas_vigilar(): void
    {
        $this->post_('cambios/estado', ['reg_medico' => 'nadie-' . substr(uniqid(), -6)])->assertOk()->assertJson(['carga' => 'ninguna']);

        $r = $this->post_('cambios/estado', ['reg_medico' => self::REG])->assertOk()->assertJson(['carga' => 'completa']);
        $this->assertContains('pacientes', explode(',', $r->json('tablas')));
    }

    public function test_estado_con_credencial_de_equipo_devuelve_el_reg_medico_de_la_credencial(): void
    {
        $token = SyncCredential::generarToken();
        SyncCredential::create([
            'reg_medico' => self::REG, 'machine_label' => 'EQUIPO-' . substr(uniqid(), -6), 'machine_host' => 'PC-TEST',
            'bound_to_machine' => false, 'token_hash' => SyncCredential::hashToken($token), 'expires_at' => now()->addYear(),
        ]);

        // Sin reg_medico en el cuerpo: el instalador del consultorio lo toma de acá (su evolucion puede no tenerlo).
        $this->postJson('/api/sync/cambios/estado', [], ['Authorization' => 'Bearer ' . $token])
            ->assertOk()->assertJson(['reg_medico' => self::REG, 'carga' => 'completa']);
    }

    public function test_estado_devuelve_el_nombre_del_medico_dueño_del_reg_medico(): void
    {
        $this->post_('cambios/estado', ['reg_medico' => self::REG])->assertOk()->assertJson(['medico_nombre' => 'Médico Cambios']);

        // Un reg_medico sin médico registrado: el campo viene vacío, no falla.
        $this->post_('cambios/estado', ['reg_medico' => 'nadie-' . substr(uniqid(), -6)])->assertOk()->assertJson(['medico_nombre' => '']);
    }

    public function test_sin_carga_inicial_completa_no_se_suben_cambios(): void
    {
        $this->post_('cambios/subir', ['reg_medico' => 'nadie-' . substr(uniqid(), -6), 'cambios' => []])
            ->assertStatus(409)->assertJson(['error' => 'sin_carga_inicial']);
    }

    public function test_crear_editar_y_borrar_en_una_tabla_comun(): void
    {
        $r = $this->subir([
            ['id' => 10, 'tabla' => 'antece_paciente', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '1', 'codeantecedente' => 'A1'], 'fila' => ['numhistoria' => 1, 'codeantecedente' => 'A1', 'detalles' => 'editado']],
            ['id' => 11, 'tabla' => 'antece_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '2', 'codeantecedente' => 'B1'], 'fila' => ['numhistoria' => 2, 'codeantecedente' => 'B1', 'detalles' => 'nuevo']],
        ]);
        $this->assertEqualsCanonicalizing(['10', '11'], $this->confirmados($r));

        $filas = DB::table('antece_paciente')->where('reg_medico', self::REG)->orderBy('numhistoria')->get();
        $this->assertCount(2, $filas);
        $this->assertSame('editado', $filas[0]->detalles);

        $this->subir([['id' => 12, 'tabla' => 'antece_paciente', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['numhistoria' => '1', 'codeantecedente' => 'A1']]]);
        $this->assertSame(1, DB::table('antece_paciente')->where('reg_medico', self::REG)->count());
    }

    public function test_borrar_una_fila_que_el_app_guarda_se_anota_para_el_telefono_y_una_que_no_no(): void
    {
        $this->subir([['id' => 13, 'tabla' => 'reposo_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['nrohistoria' => '1', 'nroconsulta' => '1'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 1, 'numdias' => 3]]]);
        $reposo = DB::table('reposo_paciente')->where('reg_medico', self::REG)->value('id');

        $this->subir([
            ['id' => 14, 'tabla' => 'reposo_paciente', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['nrohistoria' => '1', 'nroconsulta' => '1']],
            ['id' => 15, 'tabla' => 'antece_paciente', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['numhistoria' => '1', 'codeantecedente' => 'A1']],
        ]);

        $borrados = SyncChange::where('reg_medico', self::REG)->where('operation', 'deleted')->get();
        $this->assertSame([['reposo_paciente', $reposo]], $borrados->map(fn ($c) => [$c->table_name, (int) $c->record_id])->all());
    }

    public function test_borrar_una_consulta_en_el_escritorio_se_anota_para_el_telefono(): void
    {
        $consulta = DB::table('consultas')->where('reg_medico', self::REG)->value('id');

        $this->subir([['id' => 16, 'tabla' => 'consultas', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['numhistoria' => '1', 'nroconsulta' => '1']]]);

        $this->assertTrue(SyncChange::where('reg_medico', self::REG)->where('table_name', 'consultas')
            ->where('record_id', $consulta)->where('operation', 'deleted')->exists());
    }

    public function test_historia_nueva_del_escritorio_conserva_su_numero_si_esta_libre(): void
    {
        $this->subir([['id' => 1, 'tabla' => 'pacientes', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['numhistoria' => '3'], 'fila' => $this->paciente(3, 'CAMB-3', 'LUIS')]]);

        $historia = Historia::where('reg_medico', self::REG)->where('clave_escritorio', '3')->first();
        $this->assertSame('3', (string) $historia->numhistoria);
        $this->assertSame('LUIS', Paciente::find($historia->paciente_id)->nombres);
    }

    public function test_si_el_app_ya_uso_el_numero_el_api_da_otro_y_traduce_las_tablas_hijas(): void
    {
        // El app creó la historia 3 para ANA (el servidor le dio el número).
        $ana = Paciente::create(['cedula' => 'APP-' . substr(uniqid(), -6), 'nombres' => 'ANA', 'apellidos' => 'APP']);
        MedicoPaciente::create(['medico_id' => $this->medico->id, 'paciente_id' => $ana->id, 'numhistoria' => '3', 'reg_medico' => self::REG]);
        Historia::create(['paciente_id' => $ana->id, 'medico_id' => $this->medico->id, 'numhistoria' => '3', 'reg_medico' => self::REG]);

        // El escritorio, sin conexión, creó SU historia 3 para LUIS, con una consulta y un antecedente.
        $r = $this->subir([
            ['id' => 22, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => '2026-10-02 10:01:00',
             'clave' => ['numhistoria' => '3', 'nroconsulta' => '1'], 'fila' => ['numhistoria' => 3, 'nroconsulta' => 1, 'fecha' => '2026-10-02']],
            ['id' => 23, 'tabla' => 'antece_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:02:00',
             'clave' => ['numhistoria' => '3', 'codeantecedente' => 'C1'], 'fila' => ['numhistoria' => 3, 'codeantecedente' => 'C1']],
            ['id' => 21, 'tabla' => 'pacientes', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '3'], 'fila' => $this->paciente(3, 'CAMB-LUIS', 'LUIS')],
        ]);
        $this->assertEqualsCanonicalizing(['21', '22', '23'], $this->confirmados($r), 'pacientes se aplica primero aunque llegue último');

        $luis = Historia::where('reg_medico', self::REG)->where('clave_escritorio', '3')->first();
        $this->assertSame('4', (string) $luis->numhistoria, 'la 3 ya era de ANA: LUIS recibe la siguiente');
        $this->assertSame('3', (string) Historia::where('paciente_id', $ana->id)->value('numhistoria'), 'ANA no se toca');

        $consulta = DB::table('consultas')->where('reg_medico', self::REG)->where('clave_escritorio', '3|1')->first();
        $this->assertSame(4, (int) $consulta->numhistoria);
        $this->assertSame(4, (int) DB::table('antece_paciente')->where('reg_medico', self::REG)->where('codeantecedente', 'C1')->value('numhistoria'));
    }

    public function test_consulta_con_numero_ya_usado_por_el_app_recibe_otro_y_se_traducen_sus_hijas(): void
    {
        // El app creó la consulta 2 de la historia 1.
        DB::table('consultas')->insert(['reg_medico' => self::REG, 'numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => '2026-10-02', 'created_at' => now(), 'updated_at' => now()]);

        $this->subir([
            ['id' => 31, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => '2026-10-02', 'enfermedadactual' => 'DEL ESCRITORIO']],
            ['id' => 32, 'tabla' => 'examen_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2', 'codeexamen' => 'E1'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'codeexamen' => 'E1']],
        ]);

        $delEscritorio = DB::table('consultas')->where('reg_medico', self::REG)->where('clave_escritorio', '1|2')->first();
        $this->assertSame(3, (int) $delEscritorio->nroconsulta);
        $this->assertSame('DEL ESCRITORIO', $delEscritorio->enfermedadactual);
        $this->assertSame(3, (int) DB::table('examen_paciente')->where('reg_medico', self::REG)->where('codeexamen', 'E1')->value('nroconsulta'));
    }

    /**
     * **WEB-4.3**: `examen_fisico_nuevo.consulta` guarda el **número** de la consulta, no su id.
     *
     * El camino genérico traduce la clave al id del API (que es lo que necesitan las demás tablas hijas,
     * como `examen_paciente`), pero esta tabla es la excepción: su columna `consulta` es el número con el
     * que el escritorio ata la fila, y la web la lee y la escribe con ese número
     * (`App\Clinica\Consulta\ExamenFisicoDeLaConsulta`). Sin la corrección, la fila del examen quedaba en
     * otro espacio de claves que el de la carga inicial y que el de la web: dos filas para la misma
     * consulta y campos que no se ven.
     */
    public function test_el_examen_fisico_guarda_el_numero_de_la_consulta_y_no_su_id(): void
    {
        $this->subir([[
            'id' => 33, 'tabla' => 'examen_fisico_nuevo', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['historia' => '1', 'consulta' => '1'],
            'fila' => ['historia' => 1, 'consulta' => 1, 'general' => 'DEL ESCRITORIO'],
        ]]);

        $fila = DB::table('examen_fisico_nuevo')->where('reg_medico', self::REG)->first();
        $this->assertNotNull($fila, 'la fila del examen se guardó');
        $this->assertSame('DEL ESCRITORIO', $fila->general);
        $this->assertSame(1, (int) $fila->consulta, 'la columna `consulta` guarda el número, no el id');

        // Y el id de la consulta de esa historia **no** es 1, así que las dos claves no coinciden: es lo que
        // hace que la distinción importe.
        $idDeLaConsulta = DB::table('consultas')->where('reg_medico', self::REG)->where('numhistoria', 1)->where('nroconsulta', 1)->value('id');
        $this->assertNotSame(1, (int) $idDeLaConsulta);

        // La otra tabla hija sigue guardando el **id** traducido (no se cambió su comportamiento).
        $this->subir([[
            'id' => 34, 'tabla' => 'examen_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:01:00',
            'clave' => ['nrohistoria' => '1', 'nroconsulta' => '1', 'codeexamen' => 'E9'],
            'fila' => ['nrohistoria' => 1, 'nroconsulta' => 1, 'codeexamen' => 'E9'],
        ]]);

        $this->assertSame(1, (int) DB::table('examen_paciente')->where('reg_medico', self::REG)->where('codeexamen', 'E9')->value('nroconsulta'));
    }

    public function test_lo_que_cuelga_de_una_historia_que_no_subio_se_rechaza_y_no_se_confirma(): void
    {
        $r = $this->subir([['id' => 41, 'tabla' => 'antece_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['numhistoria' => '99', 'codeantecedente' => 'X'], 'fila' => ['numhistoria' => 99, 'codeantecedente' => 'X']]]);

        $this->assertSame([], $this->confirmados($r));
        $this->assertStringContainsString('41:', $r->json('rechazados'));
    }

    public function test_mover_una_cita_en_el_escritorio_borra_la_vieja_y_crea_la_nueva(): void
    {
        $vieja = Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|08:00:00')->first();

        $this->subir([
            ['id' => 51, 'tabla' => 'cola', 'op' => 'D', 'fecha' => '2026-10-02 10:00:00', 'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00']],
            ['id' => 52, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00', 'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '09:30:00'],
             'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '09:30:00', 'numhistoria' => 1, 'motivo' => 'CONTROL']],
        ]);

        $this->assertNull(Cola::find($vieja->id));
        $this->assertTrue(SyncChange::where('table_name', 'cola')->where('record_id', $vieja->id)->where('operation', 'deleted')->exists(),
            'el borrado queda en sync_changes para que el app también la saque');
        $this->assertSame('09:30:00', (string) Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|09:30:00')->value('hora_ini'));
    }

    /**
     * **Trampa 109**: `clave_escritorio` de una cita (`fecha|hora_ini`) **no es única**: el legado
     * permite dos citas a la misma hora para pacientes distintos (su clave primaria es `fecha` +
     * `numhistoria`) y AppDDR también (citas múltiples). Un cambio con esa clave compartida tiene que
     * tocar la cita de **su** historia, no la primera que aparezca.
     */
    public function test_un_cambio_con_clave_de_cita_compartida_actualiza_la_fila_de_su_historia(): void
    {
        $primera = $this->citaDeLaClave();
        $segunda = $this->citaGemela();

        $this->subir([['id' => 101, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => '2'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 2, 'motivo' => 'EDITADA']]]);

        $this->assertSame('CONTROL', (string) $primera->fresh()->motivo, 'la cita de la historia 1 no se toca');
        $this->assertSame('EDITADA', (string) $segunda->fresh()->motivo, 'se edita la de la historia 2');
    }

    public function test_un_borrado_con_clave_de_cita_compartida_borra_la_fila_de_su_historia(): void
    {
        $primera = $this->citaDeLaClave();
        $segunda = $this->citaGemela();

        $this->subir([['id' => 102, 'tabla' => 'cola', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => '2']]]);

        $this->assertNull(Cola::find($segunda->id), 'se borra la cita de la historia 2');
        $this->assertNotNull(Cola::find($primera->id), 'la de la historia 1 sigue');
    }

    /**
     * **Pediatría**: su `cola` tiene clave primaria `fecha` + `numhistoria` (los ocho esquemas de
     * `esquemas-asa/`), así que el trigger manda `{fecha, numhistoria}` y **sin** `hora_ini` — la hora
     * viaja sólo en la fila. La cita se tiene que resolver por la fecha y la historia, no por la hora.
     */
    public function test_un_cambio_de_pediatria_sin_hora_en_la_clave_actualiza_la_fila_de_su_historia(): void
    {
        $primera = $this->citaDeLaClave();
        $segunda = $this->citaGemela();

        $this->subir([['id' => 103, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['fecha' => '2026-10-01', 'numhistoria' => '2'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 2, 'motivo' => 'EDITADA']]]);

        $this->assertSame('CONTROL', (string) $primera->fresh()->motivo, 'la cita de la historia 1 no se toca');
        $this->assertSame('EDITADA', (string) $segunda->fresh()->motivo, 'se edita la de la historia 2');
    }

    public function test_un_borrado_de_pediatria_sin_hora_en_la_clave_borra_la_fila_de_su_historia(): void
    {
        $primera = $this->citaDeLaClave();
        $segunda = $this->citaGemela();

        // El borrado del trigger no trae fila: la hora no está en ninguna parte del cambio.
        $this->subir([['id' => 104, 'tabla' => 'cola', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['fecha' => '2026-10-01', 'numhistoria' => '2']]]);

        $this->assertNull(Cola::find($segunda->id), 'se borra la cita de la historia 2');
        $this->assertNotNull(Cola::find($primera->id), 'la de la historia 1 sigue');
    }

    /** Una cita nueva a una hora que **ya** tiene otro paciente no puede pisarla: son dos filas. */
    public function test_una_cita_nueva_de_pediatria_a_la_misma_hora_no_pisa_la_del_otro_paciente(): void
    {
        DB::table('cola')->insert([
            'reg_medico' => self::REG, 'fecha' => '2026-10-05', 'hora_ini' => '09:00:00',
            'numhistoria' => 1, 'motivo' => 'DEL PACIENTE 1', 'clave_escritorio' => '2026-10-05|09:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->subir([['id' => 105, 'tabla' => 'cola', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['fecha' => '2026-10-05', 'numhistoria' => '2'],
            'fila' => ['fecha' => '2026-10-05', 'hora_ini' => '09:00:00', 'numhistoria' => 2,
                'motivo' => 'DEL PACIENTE 2', 'hora_fin' => '09:20:00']]]);

        $citas = Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-05|09:00:00')->orderBy('id')->get();
        $this->assertCount(2, $citas, 'dos citas a la misma hora, no una pisada');
        $this->assertSame(['1', '2'], $citas->pluck('numhistoria')->map(fn ($h) => (string) $h)->all());
        $this->assertSame(['DEL PACIENTE 1', 'DEL PACIENTE 2'], $citas->pluck('motivo')->all());
    }

    /** La historia tiene que estar en el API: si no, se rechaza y el escritorio reintenta. */
    public function test_una_cita_de_pediatria_de_una_historia_que_no_subio_se_rechaza(): void
    {
        $respuesta = $this->subir([['id' => 106, 'tabla' => 'cola', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['fecha' => '2026-10-01', 'numhistoria' => '99']]]);

        $this->assertStringContainsString('106:la historia 99', (string) $respuesta->json('rechazados'));
        $this->assertSame([], $this->confirmados($respuesta));
    }

    /**
     * `cola.hora_ini` no es nula en el API (el escritorio la completa al agendar), así que una cita sin
     * hora no se puede guardar: el rechazo lo dice, en vez de morir con un error de la base.
     */
    public function test_una_cita_sin_hora_se_rechaza_con_un_mensaje_claro(): void
    {
        $respuesta = $this->subir([['id' => 107, 'tabla' => 'cola', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['fecha' => '2026-10-07', 'numhistoria' => '1'],
            'fila' => ['fecha' => '2026-10-07', 'numhistoria' => 1, 'motivo' => 'SIN HORA']]]);

        $this->assertStringContainsString('107:cola sin fecha/hora_ini', (string) $respuesta->json('rechazados'));
        $this->assertSame(0, Cola::where('reg_medico', self::REG)->where('motivo', 'SIN HORA')->count());
    }

    /** La cita del setUp (historia 1), que comparte clave con la gemela. */
    private function citaDeLaClave(): Cola
    {
        return Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|08:00:00')
            ->orderBy('id')->firstOrFail();
    }

    /**
     * Una segunda cita a la **misma** fecha y hora, de otra historia: la fila que el UNIQUE sobre
     * `clave_escritorio` no dejaba tener.
     */
    private function citaGemela(): Cola
    {
        DB::table('cola')->insert([
            'reg_medico' => self::REG, 'fecha' => '2026-10-01', 'hora_ini' => '08:00:00',
            'numhistoria' => 2, 'motivo' => 'DE LA HISTORIA 2', 'clave_escritorio' => '2026-10-01|08:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|08:00:00')
            ->orderByDesc('id')->firstOrFail();
    }

    public function test_gana_la_ultima_edicion_por_columna(): void
    {
        $cita = Cola::where('reg_medico', self::REG)->first();
        // La secretaria cambió el motivo en el app DESPUÉS de que el médico editara en el escritorio.
        SyncChange::create(['reg_medico' => self::REG, 'table_name' => 'cola', 'record_id' => $cita->id, 'operation' => 'updated',
            'column_name' => 'motivo', 'value' => 'DEL APP', 'occurred_at' => '2026-10-02 10:05:00', 'source' => 'mobile']);
        $cita->motivo = 'DEL APP';
        $cita->save();

        $r = $this->subir([['id' => 61, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'motivo' => 'DEL ESCRITORIO', 'hora_fin' => '08:45:00']]]);

        $cita->refresh();
        $this->assertSame('DEL APP', $cita->motivo, 'el app editó el motivo después: gana el app');
        $this->assertSame('08:45:00', (string) $cita->hora_fin, 'la hora de fin la cambió solo el escritorio: se aplica');
        $this->assertSame(1, (int) $r->json('parciales'));
        $this->assertTrue(SyncChange::where('record_id', $cita->id)->where('source', 'escritorio')->where('column_name', 'hora_fin')->exists(),
            'la edición del escritorio queda anotada para que el app la respete');
    }

    public function test_una_factura_del_escritorio_no_se_pinta_como_confirmada(): void
    {
        // La secretaria le elaboró la factura a la cita del día (el escritorio usa `estado = 1`).
        $this->subir([['id' => 62, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'estado' => 1]]]);

        $cita = Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|08:00:00')->first();
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $cita->estado,
            'en AppDDR `estado` es la confirmación: la factura del escritorio no la escribe');
        $this->assertTrue((bool) $cita->facturada_escritorio, 'la factura del escritorio se conserva aparte');
    }

    public function test_una_confirmacion_de_appddr_sobrevive_a_la_edicion_del_escritorio(): void
    {
        $cita = Cola::where('reg_medico', self::REG)->first();
        // La secretaria confirmó la cita desde la web (AppDDR escribe `estado = 1`).
        $cita->estado = Cola::ESTADO_CONFIRMADA;
        $cita->save();
        SyncChange::create(['reg_medico' => self::REG, 'table_name' => 'cola', 'record_id' => $cita->id, 'operation' => 'updated',
            'column_name' => 'estado', 'value' => Cola::ESTADO_CONFIRMADA, 'occurred_at' => '2026-10-02 10:05:00', 'source' => 'mobile']);

        // El médico le cambia la hora de fin en el escritorio: la fila viaja con `estado = 0`.
        $this->subir([['id' => 63, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 10:10:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'estado' => 0, 'hora_fin' => '08:40:00']]]);

        $cita->refresh();
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $cita->estado, 'el escritorio no confirma ni desconfirma');
        $this->assertSame('08:40:00', (string) $cita->hora_fin, 'el resto de la edición sí se aplica');
        $this->assertNull($cita->facturada_escritorio, 'el 0 del escritorio no es una factura');
    }

    public function test_confirmada_por_el_paciente_no_la_pisa_una_factura_del_escritorio(): void
    {
        // `estado = 2` (lo confirmó el paciente) es el caso que el `if` del escritorio no cubre.
        $cita = Cola::where('reg_medico', self::REG)->first();
        $cita->estado = Cola::ESTADO_CONFIRMADA_PACIENTE;
        $cita->save();

        $this->subir([['id' => 64, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'estado' => 1]]]);

        $cita->refresh();
        $this->assertSame(Cola::ESTADO_CONFIRMADA_PACIENTE, (int) $cita->estado, 'AppDDR conserva su confirmación');
        $this->assertTrue((bool) $cita->facturada_escritorio, 'y la factura del escritorio queda registrada');
    }

    public function test_una_cita_nueva_del_escritorio_nace_sin_confirmar(): void
    {
        $this->subir([['id' => 65, 'tabla' => 'cola', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['fecha' => '2026-10-03', 'hora_ini' => '09:00:00'],
            'fila' => ['fecha' => '2026-10-03', 'hora_ini' => '09:00:00', 'numhistoria' => 1, 'estado' => 1]]]);

        $cita = Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-03|09:00:00')->first();
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $cita->estado, 'la agenda del escritorio no confirma');
        $this->assertTrue((bool) $cita->facturada_escritorio);
    }

    public function test_una_cita_movida_por_el_escritorio_no_queda_pendiente_en_appddr(): void
    {
        // `w_calendar` marca la cita vieja con `atendido = 2` cuando la pasa a otro día (WEB-2.13).
        $this->subir([['id' => 66, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 12:00:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'atendido' => 2]]]);

        $cita = Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|08:00:00')->first();
        $this->assertSame(0, (int) $cita->atendido, 'el 2 del escritorio no es un valor de AppDDR');
        $this->assertTrue((bool) $cita->movida_escritorio, 'la movida se conserva aparte');
    }

    public function test_un_cero_del_escritorio_vuelve_a_dejar_normal_una_cita_movida(): void
    {
        // Reagendar en el mismo día (`w_nueva_cita_7`) resetea `atendido`: la marca no es de un solo
        // sentido, a diferencia de `facturada_escritorio`.
        $cita = Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|08:00:00')->first();
        $cita->movida_escritorio = true;
        $cita->save();

        $this->subir([['id' => 67, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 12:30:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'atendido' => 0]]]);

        $this->assertFalse((bool) $cita->fresh()->movida_escritorio);
    }

    public function test_realizada_del_escritorio_cuenta_como_atendida(): void
    {
        // `3 = Realizada` está declarado en el DataWindow y ninguna ventana lo escribe; si aparece,
        // es una consulta hecha (decisión 2026-10-09).
        $this->subir([['id' => 68, 'tabla' => 'cola', 'op' => 'U', 'fecha' => '2026-10-02 13:00:00',
            'clave' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00'],
            'fila' => ['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'numhistoria' => 1, 'atendido' => 3]]]);

        $cita = Cola::where('reg_medico', self::REG)->where('clave_escritorio', '2026-10-01|08:00:00')->first();
        $this->assertSame(1, (int) $cita->atendido);
        $this->assertFalse((bool) $cita->movida_escritorio);
    }

    public function test_tabla_sin_clave_primaria_se_reemplaza_entera(): void
    {
        DB::table('vademecum_m')->insert(['reg_medico' => self::REG, 'codemedicina' => 'VIEJO']);

        $this->subir([['id' => 71, 'tabla' => 'vademecum_m', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['codemedicina' => 'NUEVO1'], ['codemedicina' => 'NUEVO2']]]]);

        $this->assertEqualsCanonicalizing(['NUEVO1', 'NUEVO2'],
            DB::table('vademecum_m')->where('reg_medico', self::REG)->pluck('codemedicina')->all());
    }

    public function test_reemplazar_una_tabla_sin_clave_no_borra_lo_que_creo_el_app(): void
    {
        DB::table('recipe_detalle')->insert(['reg_medico' => self::REG, 'nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 1]);
        $delApp = DB::table('recipe_detalle')->insertGetId(['reg_medico' => self::REG, 'nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 2]);
        SyncChange::create(['reg_medico' => self::REG, 'table_name' => 'recipes', 'record_id' => $delApp, 'operation' => 'created',
            'occurred_at' => now(), 'source' => 'mobile']);

        $this->subir([['id' => 72, 'tabla' => 'recipe_detalle', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 1], ['nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 3]]]]);

        $this->assertEqualsCanonicalizing([1, 2, 3],
            DB::table('recipe_detalle')->where('reg_medico', self::REG)->pluck('recipe')->map(fn ($r) => (int) $r)->all(),
            'el récipe 2 lo creó el app y se conserva');
    }

    public function test_reemplazar_una_tabla_sin_clave_no_borra_la_cabecera_de_recipe_que_creo_la_web(): void
    {
        // WEB-5.1: la web crea cabeceras de récipe con la misma convención de rastro que el móvil (el `created`
        // de `recipes` con el id de la cabecera), pero con `source = web`. El reemplazo de `recipe_detalle` del
        // escritorio las tiene que conservar igual.
        DB::table('recipe_detalle')->insert(['reg_medico' => self::REG, 'nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 1]);
        $delaWeb = DB::table('recipe_detalle')->insertGetId(['reg_medico' => self::REG, 'nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 2]);
        $ajena = DB::table('recipe_detalle')->insertGetId(['reg_medico' => self::REG, 'nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 3]);
        SyncChange::create(['reg_medico' => self::REG, 'table_name' => 'recipes', 'record_id' => $delaWeb, 'operation' => 'created',
            'occurred_at' => now(), 'source' => 'web']);

        $this->subir([['id' => 73, 'tabla' => 'recipe_detalle', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'recipe' => 1]]]]);

        $this->assertEqualsCanonicalizing([1, 2],
            DB::table('recipe_detalle')->where('reg_medico', self::REG)->pluck('recipe')->map(fn ($r) => (int) $r)->all(),
            'el récipe 2 lo creó la web y se conserva; el 3 no lo creó nadie del app y se reemplaza');
        $this->assertNull(DB::table('recipe_detalle')->find($ajena));
    }

    public function test_reemplazar_recipes_pareja_conserva_las_filas_que_creo_la_web_y_reemplaza_el_resto(): void
    {
        // WEB-5.1b: `recipes_pareja` no tiene clave primaria en el escritorio, que la sube entera (`op = T`), y el
        // reemplazo borra todo lo del médico. Las filas que creó la web (el `created` de cada fila, hecho por
        // la Action real) se conservan; las que no creó nadie del app se reemplazan por lo que manda el escritorio.
        $consulta = \App\Models\Consulta::where('reg_medico', self::REG)->where('numhistoria', 1)->where('nroconsulta', 1)->firstOrFail();
        DB::table('recipes_pareja')->insert(['reg_medico' => self::REG, 'nrohistoria' => 1, 'nroconsulta' => 1,
            'codemedicina' => 'VIEJO', 'cedula' => '111', 'nombre' => 'DEL ESCRITORIO']);

        app(\App\Actions\Consulta\GuardarRecipeDeLaPareja::class)->ejecutar(
            $consulta, '12345678', 'MARIANA', [['codemedicina' => 'WEB01', 'cantidad' => 1, 'descripcion' => 'X', 'nombre' => 'X']], null, 'web');

        $this->subir([['id' => 74, 'tabla' => 'recipes_pareja', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'codemedicina' => 'NUEVO', 'cedula' => '222', 'nombre' => 'OTRA']]]]);

        $this->assertEqualsCanonicalizing(['WEB01', 'NUEVO'],
            DB::table('recipes_pareja')->where('reg_medico', self::REG)->pluck('codemedicina')->all(),
            'la fila de la web se conserva; la que nadie del app creó se reemplaza por lo que subió el escritorio');
        $this->assertSame('MARIANA', DB::table('recipes_pareja')->where('reg_medico', self::REG)->where('codemedicina', 'WEB01')->value('nombre'));
    }

    public function test_reemplazar_los_tratamientos_conserva_los_que_creo_la_web_y_reemplaza_el_resto(): void
    {
        // WEB-8.1a: `recipe_grupo` y `recipe_grupo_detalle` no tienen clave primaria en el escritorio (cada una tiene sólo el
        // trigger de sentencia): lo sube ENTERO y el reemplazo borra todo lo del médico antes de insertar lo que llegó. Los
        // tratamientos que creó la web (el `created` de cada fila, hecho por la Action real, bajo el nombre de su tabla) se
        // conservan; lo que nadie del app creó se reemplaza por lo que manda el escritorio.
        DB::table('recipe_grupo')->insert(['reg_medico' => self::REG, 'codigo' => '0050', 'tratamiento' => 'VIEJO DEL ESCRITORIO']);
        DB::table('recipe_grupo_detalle')->insert(['reg_medico' => self::REG, 'codigo' => '0050', 'codemedicina' => 'V1', 'cantidad' => 1, 'orden' => 0]);

        $resultado = app(\App\Actions\Catalogos\GuardarTratamiento::class)->ejecutar(
            self::REG, [self::REG], null, 'De la web',
            [
                ['codemedicina' => 'W1', 'cantidad' => 1, 'indicaciones' => 'Uno', 'descripcion' => 'W1', 'nombre' => 'W1'],
                ['codemedicina' => 'W2', 'cantidad' => 2, 'indicaciones' => 'Dos', 'descripcion' => 'W2', 'nombre' => 'W2'],
            ],
            null, 'web',
        );
        $this->assertSame('0051', $resultado->tratamiento->codigo, 'el correlativo sigue al mayor, también al del escritorio');

        $this->subir([
            ['id' => 75, 'tabla' => 'recipe_grupo', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
                'filas' => [['codigo' => '0060', 'tratamiento' => 'NUEVO DEL ESCRITORIO']]],
            ['id' => 76, 'tabla' => 'recipe_grupo_detalle', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
                'filas' => [['codigo' => '0060', 'codemedicina' => 'N1', 'cantidad' => 1, 'orden' => 0]]],
        ]);

        $this->assertEqualsCanonicalizing(['DE LA WEB', 'NUEVO DEL ESCRITORIO'],
            DB::table('recipe_grupo')->where('reg_medico', self::REG)->pluck('tratamiento')->all(),
            'el de la web se conserva; el viejo del escritorio se reemplaza por lo que manda el escritorio');
        $this->assertEqualsCanonicalizing(['W1', 'W2', 'N1'],
            DB::table('recipe_grupo_detalle')->where('reg_medico', self::REG)->pluck('codemedicina')->all());
    }

    public function test_sin_las_lineas_de_config_de_los_tratamientos_la_subida_si_los_borraria(): void
    {
        // El contraste que ata el test anterior a la configuración: sin `recipe_grupo` ni `recipe_grupo_detalle` en
        // `sin_clave_con_altas_del_app`, el reemplazo de la tabla se lleva lo que creó la web.
        $this->assertArrayHasKey('recipe_grupo', config('sync_legado.sin_clave_con_altas_del_app'));
        $this->assertArrayHasKey('recipe_grupo_detalle', config('sync_legado.sin_clave_con_altas_del_app'));

        app(\App\Actions\Catalogos\GuardarTratamiento::class)->ejecutar(
            self::REG, [self::REG], null, 'De la web',
            [['codemedicina' => 'W1', 'cantidad' => 1, 'indicaciones' => 'Uno', 'descripcion' => 'W1', 'nombre' => 'W1']],
            null, 'web',
        );

        config(['sync_legado.sin_clave_con_altas_del_app' => ['recipe_detalle' => 'recipes', 'recipes_pareja' => 'recipes_pareja']]);

        $this->subir([
            ['id' => 77, 'tabla' => 'recipe_grupo', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00', 'filas' => []],
            ['id' => 78, 'tabla' => 'recipe_grupo_detalle', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00', 'filas' => []],
        ]);

        $this->assertSame(0, DB::table('recipe_grupo')->where('reg_medico', self::REG)->count());
        $this->assertSame(0, DB::table('recipe_grupo_detalle')->where('reg_medico', self::REG)->count());
    }

    public function test_reemplazar_motivo_cita_conserva_los_motivos_que_creo_la_web_y_el_precio_de_los_del_escritorio(): void
    {
        // WEB-8.1c: `motivo_cita` no tiene clave primaria en el escritorio (sólo un índice único llamado `primarykey`), así que
        // su registro de cambios es de sentencia (`op = T`): lo sube ENTERO (sólo `codigo` y `tipo_atencion`) y el reemplazo
        // borra todo lo del médico. Dos cosas se conservan: (1) los motivos que creó la web (su `created`, hecho por la Action
        // real) y (2) lo que el escritorio no conoce —el precio, la sede y la marca— de los motivos que vienen de él.
        DB::table('motivo_cita')->insert(['reg_medico' => self::REG, 'codigo' => '01', 'tipo_atencion' => 'PRIMERA CONSULTA',
            'precio' => 150.5, 'admite_varias_citas_pendientes' => 1]);
        DB::table('motivo_cita')->insert(['reg_medico' => self::REG, 'codigo' => '02', 'tipo_atencion' => 'VIEJO QUE YA NO EXISTE']);

        $web = app(\App\Actions\Catalogos\CrearMotivoDeCita::class)->ejecutar($this->medico, self::REG, ['razon' => 'De la web', 'precio' => 40], null, 'web');
        $this->assertSame('03', $web->codigo, 'el correlativo sigue al mayor, también al del escritorio');

        $this->subir([['id' => 79, 'tabla' => 'motivo_cita', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['codigo' => '01', 'tipo_atencion' => 'PRIMERA CONSULTA RENOMBRADA'], ['codigo' => '09', 'tipo_atencion' => 'NUEVO DEL ESCRITORIO']]]]);

        $filas = DB::table('motivo_cita')->where('reg_medico', self::REG)->orderBy('codigo')->get()->keyBy('codigo');
        $this->assertEqualsCanonicalizing(['01', '03', '09'], $filas->keys()->all(), 'el de la web se conserva; el viejo que el escritorio ya no tiene se va');

        // El que el escritorio vuelve a mandar conserva lo que la web le puso; el nombre es el que mandó el escritorio.
        $this->assertSame('PRIMERA CONSULTA RENOMBRADA', $filas['01']->tipo_atencion);
        $this->assertEquals(150.5, $filas['01']->precio);
        $this->assertEquals(1, $filas['01']->admite_varias_citas_pendientes);
        $this->assertSame('DE LA WEB', $filas['03']->tipo_atencion);
        $this->assertEquals(40, $filas['03']->precio);
        $this->assertNull($filas['09']->precio);
    }

    public function test_reemplazar_motivo_cita_devuelve_al_codigo_repetido_la_fila_sin_sede(): void
    {
        // Si el mismo código está sin sede y en una sede (lo deja la sincronización), el escritorio —que no conoce las
        // sedes— manda una sola fila: recupera el precio de la que no tiene sede.
        $centro = \App\Models\MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'), 'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'), 'name' => 'Centro', 'address' => 'X',
        ]);
        $sede = \App\Models\Office::create(['medical_center_id' => $centro->id, 'medico_id' => $this->medico->id, 'reg_medico' => self::REG,
            'office_number' => 'C1', 'modalidad' => 'hora_cita', 'duracion_cita' => 30, 'activo' => true]);
        DB::table('motivo_cita')->insert(['reg_medico' => self::REG, 'codigo' => 'PRIM', 'tipo_atencion' => 'DE SEDE', 'precio' => 80, 'office_id' => $sede->id]);
        DB::table('motivo_cita')->insert(['reg_medico' => self::REG, 'codigo' => 'PRIM', 'tipo_atencion' => 'GENERAL', 'precio' => 150]);

        $this->subir([['id' => 80, 'tabla' => 'motivo_cita', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['codigo' => 'PRIM', 'tipo_atencion' => 'PRIMERA']]]]);

        $fila = DB::table('motivo_cita')->where('reg_medico', self::REG)->get();
        $this->assertCount(1, $fila);
        $this->assertEquals(150, $fila[0]->precio);
        $this->assertNull($fila[0]->office_id);
    }

    public function test_sin_las_lineas_de_config_de_motivo_cita_la_subida_si_borraria_lo_de_la_web(): void
    {
        // El contraste que ata los tests anteriores a la configuración.
        $this->assertArrayHasKey('motivo_cita', config('sync_legado.sin_clave_con_altas_del_app'));
        $this->assertArrayHasKey('motivo_cita', config('sync_legado.columnas_del_app_en_el_reemplazo'));

        app(\App\Actions\Catalogos\CrearMotivoDeCita::class)->ejecutar($this->medico, self::REG, ['razon' => 'De la web', 'precio' => 40], null, 'web');
        DB::table('motivo_cita')->insert(['reg_medico' => self::REG, 'codigo' => '01', 'tipo_atencion' => 'DEL ESCRITORIO', 'precio' => 10]);

        config([
            'sync_legado.sin_clave_con_altas_del_app' => ['recipe_detalle' => 'recipes'],
            'sync_legado.columnas_del_app_en_el_reemplazo' => [],
        ]);

        $this->subir([['id' => 81, 'tabla' => 'motivo_cita', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['codigo' => '01', 'tipo_atencion' => 'DEL ESCRITORIO']]]]);

        $this->assertSame(['01'], DB::table('motivo_cita')->where('reg_medico', self::REG)->pluck('codigo')->all(), 'la fila de la web se perdió');
        $this->assertNull(DB::table('motivo_cita')->where('reg_medico', self::REG)->value('precio'), 'y el precio del que vuelve también');
    }

    public function test_el_vademecum_se_sube_por_fila_y_no_pierde_lo_que_creo_la_web(): void
    {
        // WEB-8.1a: `vademecum` SÍ tiene clave primaria en el escritorio (`codemedicina`): lleva los tres triggers de
        // fila, se sube con `I`/`U`/`D` por clave y jamás con el reemplazo de la tabla. Por eso no necesita estar en
        // `sin_clave_con_altas_del_app`, y una subida de OTRO código no toca lo que creó la web.
        $this->assertArrayNotHasKey('vademecum', config('sync_legado.sin_clave_con_altas_del_app'));

        [$web] = app(\App\Actions\Catalogos\CrearMedicamentoEnElCatalogo::class)->ejecutar(
            self::REG, [self::REG], ['nombrecomercial' => 'De la web', 'presentacion' => 'Jarabe'], null, 'web',
        );
        DB::table('vademecum')->insert(['reg_medico' => self::REG, 'codemedicina' => '0600', 'nombrecomercial' => 'BORRABLE']);

        $this->subir([
            ['id' => 79, 'tabla' => 'vademecum', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
                'clave' => ['codemedicina' => '0500'], 'fila' => ['codemedicina' => '0500', 'nombrecomercial' => 'DEL ESCRITORIO']],
            ['id' => 80, 'tabla' => 'vademecum', 'op' => 'D', 'fecha' => '2026-10-02 10:00:00', 'clave' => ['codemedicina' => '0600']],
        ]);

        $this->assertEqualsCanonicalizing([$web->codemedicina, '0500'],
            DB::table('vademecum')->where('reg_medico', self::REG)->pluck('codemedicina')->all(),
            'la fila de la web sigue; la del escritorio entra y la que borró el escritorio se va');
        $this->assertNotNull(DB::table('vademecum')->find($web->id));
        $this->assertSame('DE LA WEB', DB::table('vademecum')->find($web->id)->nombrecomercial);
    }

    public function test_tabla_fuera_de_la_lista_blanca_se_rechaza(): void
    {
        $r = $this->subir([['id' => 81, 'tabla' => 'users', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['id' => 1], 'fila' => ['name' => 'x']]]);

        $this->assertSame([], $this->confirmados($r));
        $this->assertStringContainsString('tabla no permitida', $r->json('rechazados'));
    }

    public function test_los_usuarios_del_escritorio_no_se_sincronizan(): void
    {
        // `operadores` son los usuarios del escritorio, con la contraseña en texto plano.
        $this->assertNotContains('operadores', config('sync_legado.tablas'));

        $r = $this->subir([['id' => 82, 'tabla' => 'operadores', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
            'clave' => ['primera' => 'x'], 'fila' => ['primera' => 'x', 'segunda' => 'secreto']]]);
        $this->assertSame([], $this->confirmados($r));
        $this->assertSame(0, DB::table('operadores')->where('reg_medico', self::REG)->count());
    }

    /**
     * **WEB-3.4**: `representante` es una tabla del legado más de la lista blanca y sigue subiendo por el
     * camino genérico, sin que el sync se haya tocado. La web le agregó tres columnas **nullable**
     * (`cedula`, `telefono`, `email`), así que este test fija las dos mitades del criterio de la tarea:
     * una fila del escritorio **con las columnas de siempre y ninguna nueva** entra igual, y una con los
     * datos de contacto nuevos los guarda. Además comprueba lo que la tabla tiene de particular: su clave
     * real es el `numhistoria` (`PRIMARY KEY (numhistoria)` en el esquema del legado), así que el número
     * se traduce como el de cualquier tabla hija y una historia que todavía no subió se rechaza.
     */
    public function test_la_tabla_representante_sigue_subiendo_por_el_camino_generico(): void
    {
        $r = $this->subir([
            ['id' => 91, 'tabla' => 'representante', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '1'],
             'fila' => ['numhistoria' => 1, 'nombre' => 'ROSA PEREZ', 'codeparentesco' => 'M', 'direccion' => 'CALLE 1']],
            ['id' => 92, 'tabla' => 'representante', 'op' => 'I', 'fecha' => '2026-10-02 10:00:01',
             'clave' => ['numhistoria' => '2'],
             'fila' => ['numhistoria' => 2, 'nombre' => 'LUIS GOMEZ', 'codeparentesco' => 'P',
                        'cedula' => '12345678', 'telefono' => '04141112233', 'email' => 'luis@example.com']],
        ]);
        $this->assertEqualsCanonicalizing(['91', '92'], $this->confirmados($r));

        $viejo = DB::table('representante')->where('reg_medico', self::REG)->where('numhistoria', 1)->first();
        $this->assertSame('ROSA PEREZ', $viejo->nombre);
        $this->assertSame('M', $viejo->codeparentesco);
        $this->assertSame('CALLE 1', $viejo->direccion);
        $this->assertNull($viejo->cedula, 'las columnas nuevas son nullable: la fila del escritorio viejo entra igual');
        $this->assertNull($viejo->telefono);
        $this->assertNull($viejo->email);

        $nuevo = DB::table('representante')->where('reg_medico', self::REG)->where('numhistoria', 2)->first();
        $this->assertSame('12345678', $nuevo->cedula);
        $this->assertSame('04141112233', $nuevo->telefono);
        $this->assertSame('luis@example.com', $nuevo->email);

        // Editar en el escritorio actualiza la MISMA fila (la clave es el `numhistoria`).
        $this->subir([['id' => 93, 'tabla' => 'representante', 'op' => 'U', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['numhistoria' => '1'],
            'fila' => ['numhistoria' => 1, 'nombre' => 'ROSA PEREZ DE PRUEBA', 'codeparentesco' => 'M', 'telefono' => '04249998877']]]);

        $this->assertSame(2, DB::table('representante')->where('reg_medico', self::REG)->count());
        $viejo = DB::table('representante')->where('reg_medico', self::REG)->where('numhistoria', 1)->first();
        $this->assertSame('ROSA PEREZ DE PRUEBA', $viejo->nombre);
        $this->assertSame('04249998877', $viejo->telefono);

        // Y una fila de una historia que todavía no subió se rechaza, como cualquier tabla hija.
        $r = $this->subir([['id' => 94, 'tabla' => 'representante', 'op' => 'I', 'fecha' => '2026-10-02 12:00:00',
            'clave' => ['numhistoria' => '99'], 'fila' => ['numhistoria' => 99, 'nombre' => 'NADIE']]]);
        $this->assertSame([], $this->confirmados($r));
        $this->assertStringContainsString('todavía no está en el API', $r->json('rechazados'));
    }

    public function test_estado_devuelve_el_prefijo_del_medico_o_null(): void
    {
        $this->post_('cambios/estado', ['reg_medico' => self::REG])->assertOk()
            ->assertJson(['medico_nombre' => 'Médico Cambios', 'medico_prefix' => null]);

        $this->medico->update(['prefix' => 'Dra']);

        // El nombre no lleva el prefijo: el escritorio los une (Doct) y usa el nombre solo (docti).
        $this->post_('cambios/estado', ['reg_medico' => self::REG])->assertOk()
            ->assertJson(['medico_nombre' => 'Médico Cambios', 'medico_prefix' => 'Dra.']);

        $this->post_('cambios/estado', ['reg_medico' => 'nadie-' . substr(uniqid(), -6)])->assertOk()
            ->assertJson(['medico_nombre' => '', 'medico_prefix' => null]);
    }

    /**
     * **WEB-5.3a**: `constancia_obs`, `reposo_paciente`, `referencia` e `informe` tienen **clave primaria** en el PowerBuilder
     * (`(historia, consulta)`, y `(historia, consulta, médico)` la referencia), así que el escritorio las sube **fila por fila**
     * (`I`/`U`/`D`) y no con el reemplazo de la tabla entera (`T`) que borra las filas de la web (trampas 40 y 43). Por eso no
     * necesitan estar en `sin_clave_con_altas_del_app`. Este test fija lo que sí hay que garantizar: lo que el escritorio sube
     * y borra **de otras filas** no toca las que creó la web, en las cuatro tablas.
     */
    public function test_la_subida_fila_por_fila_de_las_solicitudes_no_toca_las_filas_que_creo_la_web(): void
    {
        $this->assertSame([], array_intersect(
            ['constancia_obs', 'reposo_paciente', 'referencia', 'informe'],
            array_keys(config('sync_legado.sin_clave_con_altas_del_app')),
        ), 'las cuatro tienen clave: se suben por fila y no se reemplazan enteras');

        $consulta = \App\Models\Consulta::where('reg_medico', self::REG)->where('numhistoria', 1)->where('nroconsulta', 1)->firstOrFail();

        DB::table('especial')->insert(['reg_medico' => self::REG, 'codeespecial' => '002', 'especialidad' => 'CARDIOLOGIA']);
        DB::table('doctores')->insert(['reg_medico' => self::REG, 'cedula' => 7, 'apellidos' => 'PEREZ', 'nombres' => 'LUIS', 'codeespecial' => '002']);

        // Lo que escribe la web, con las Actions reales (cada una deja su `created` con `source = web`).
        app(\App\Actions\Consulta\GuardarConstanciaDeLaConsulta::class)->ejecutar($consulta, null, 'OBS WEB', null, 'web');
        app(\App\Actions\Consulta\GuardarReposoDeLaConsulta::class)->ejecutar($consulta, 5, '2026-10-10', 'REPOSO WEB', null, 'web');
        app(\App\Actions\Consulta\GuardarReferenciaDeLaConsulta::class)->ejecutar($consulta, [self::REG], 7, 'MOTIVO WEB', null, 'web');
        app(\App\Actions\Consulta\GuardarInformeDeLaConsulta::class)->ejecutar($consulta, 'PARA WEB', 'INFORME WEB', '2026-10-10', null, 'web');

        // El escritorio crea otra consulta (1|2) y sube sus filas; después borra las de esa consulta y una referencia ajena.
        $fecha = '2026-10-02 10:00:00';
        $this->subir([
            ['id' => 201, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['numhistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => '2026-10-02']],
            ['id' => 202, 'tabla' => 'constancia_obs', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['numhistoria' => '1', 'numconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'numconsulta' => 2, 'observacion01' => 'DEL ESCRITORIO']],
            ['id' => 203, 'tabla' => 'reposo_paciente', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'codereposo' => 'M', 'numdias' => 3]],
            ['id' => 204, 'tabla' => 'referencia', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2', 'ceduladoctor' => '9'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'ceduladoctor' => 9, 'referencia' => 'X']],
            ['id' => 205, 'tabla' => 'informe', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'descripcion' => 'DEL ESCRITORIO']],
        ]);

        $this->subir([
            ['id' => 206, 'tabla' => 'constancia_obs', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['numhistoria' => '1', 'numconsulta' => '2']],
            ['id' => 207, 'tabla' => 'reposo_paciente', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2']],
            ['id' => 208, 'tabla' => 'referencia', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2', 'ceduladoctor' => '9']],
            ['id' => 209, 'tabla' => 'informe', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2']],
        ]);

        $this->assertSame('OBS WEB', DB::table('constancia_obs')->where('reg_medico', self::REG)->where('numconsulta', 1)->value('observacion01'));
        $this->assertSame(5, (int) DB::table('reposo_paciente')->where('reg_medico', self::REG)->where('nroconsulta', 1)->value('numdias'));
        $this->assertSame('MOTIVO WEB', DB::table('referencia')->where('reg_medico', self::REG)->where('nroconsulta', 1)->value('referencia'));
        $this->assertSame('INFORME WEB', DB::table('informe')->where('reg_medico', self::REG)->where('nroconsulta', 1)->value('descripcion'));
    }

    /**
     * **WEB-5.3b**: `hospitalizacion` tiene **clave primaria** en el PowerBuilder (`(nrohistoria, nroconsulta)`, los tres triggers de fila:
     * `tr_ddrsync_hospitalizacion_ins/_upd/_del`), así que el escritorio la sube **fila por fila** (`I`/`U`/`D`) y no con el reemplazo de
     * la tabla entera: no necesita estar en `sin_clave_con_altas_del_app` y lo que sube y borra de **otra** fila no toca la que creó la web.
     * Lo que sí pasa, y queda fijado para que no sorprenda (trampa 72): si el escritorio sube **la misma clave**, su `UPDATE` pisa lo que la
     * web editó.
     */
    public function test_la_subida_fila_por_fila_de_la_hospitalizacion_no_toca_las_filas_que_creo_la_web(): void
    {
        $this->assertArrayNotHasKey('hospitalizacion', config('sync_legado.sin_clave_con_altas_del_app'), 'tiene clave: se sube por fila');

        $consulta = \App\Models\Consulta::where('reg_medico', self::REG)->where('numhistoria', 1)->where('nroconsulta', 1)->firstOrFail();
        app(\App\Actions\Consulta\GuardarHospitalizacionDeLaConsulta::class)->ejecutar($consulta, 'CLINICA WEB', 'DX WEB', 'REC WEB', null, 'web');

        // El escritorio crea otra consulta (1|2), le sube su orden y después la borra.
        $fecha = '2026-10-02 10:00:00';
        $this->subir([
            ['id' => 210, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['numhistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => '2026-10-02']],
            ['id' => 211, 'tabla' => 'hospitalizacion', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'hospital' => 'DEL ESCRITORIO']],
        ]);
        $this->assertSame('DEL ESCRITORIO', DB::table('hospitalizacion')->where('reg_medico', self::REG)->where('nroconsulta', 2)->value('hospital'));
        $this->assertSame('CLINICA WEB', DB::table('hospitalizacion')->where('reg_medico', self::REG)->where('nroconsulta', 1)->value('hospital'));

        $this->subir([['id' => 212, 'tabla' => 'hospitalizacion', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2']]]);

        $this->assertSame(['CLINICA WEB'], DB::table('hospitalizacion')->where('reg_medico', self::REG)->pluck('hospital')->all(), 'se fue la del escritorio y quedó la de la web');
        $this->assertSame('DX WEB', DB::table('hospitalizacion')->where('reg_medico', self::REG)->value('diagprev'));

        // La misma clave: el UPDATE del escritorio pisa lo que la web editó (no consulta `sync_changes`). Límite conocido, trampa 72.
        $this->subir([['id' => 213, 'tabla' => 'hospitalizacion', 'op' => 'U', 'fecha' => '2026-10-02 12:00:00',
            'clave' => ['nrohistoria' => '1', 'nroconsulta' => '1'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 1, 'hospital' => 'PISADA POR EL ESCRITORIO']]]);
        $this->assertSame('PISADA POR EL ESCRITORIO', DB::table('hospitalizacion')->where('reg_medico', self::REG)->where('nroconsulta', 1)->value('hospital'));
    }

    /**
     * **WEB-5.3b**: `dieta_paciente` NO tiene clave primaria en el PowerBuilder (sólo un índice único sobre `(histroria, consulta)`) y su
     * registro de cambios es de sentencia (`tr_ddrsync_dieta_paciente`, `op = T`): la sube ENTERA y el reemplazo borra todo lo del médico.
     * Las dietas que creó la web (el `created` de cada fila, hecho por la Action real) se conservan; lo que nadie del app creó se reemplaza.
     */
    public function test_reemplazar_dieta_paciente_conserva_las_dietas_que_creo_la_web_y_reemplaza_el_resto(): void
    {
        $consulta = \App\Models\Consulta::where('reg_medico', self::REG)->where('numhistoria', 1)->where('nroconsulta', 1)->firstOrFail();
        DB::table('dieta_paciente')->insert(['reg_medico' => self::REG, 'histroria' => 1, 'consulta' => 9, 'cod_dieta' => '01', 'descripcion' => 'VIEJA DEL ESCRITORIO', 'ejecutada' => 'S']);

        app(\App\Actions\Consulta\GuardarDietaDeLaConsulta::class)->ejecutar($consulta, 'DIETA WEB', 'TEXTO WEB', null, 'web');

        // El escritorio crea otra consulta (1|2) y sube la tabla entera con una dieta de esa consulta.
        $fecha = '2026-10-02 10:00:00';
        $this->subir([['id' => 214, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => $fecha,
            'clave' => ['numhistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => '2026-10-02']]]);
        $this->subir([['id' => 215, 'tabla' => 'dieta_paciente', 'op' => 'T', 'fecha' => $fecha,
            'filas' => [['histroria' => 1, 'consulta' => 2, 'cod_dieta' => '02', 'descripcion' => 'NUEVA DEL ESCRITORIO', 'ejecutada' => 'S']]]]);

        $this->assertEqualsCanonicalizing(['TEXTO WEB', 'NUEVA DEL ESCRITORIO'],
            DB::table('dieta_paciente')->where('reg_medico', self::REG)->pluck('descripcion')->all(),
            'la de la web se conserva; la vieja que el escritorio ya no tiene se va y la nueva entra');
        $this->assertSame(2, (int) DB::table('dieta_paciente')->where('reg_medico', self::REG)->where('descripcion', 'NUEVA DEL ESCRITORIO')->value('consulta'),
            'el número de consulta del escritorio se traduce al del API');
    }

    public function test_sin_la_linea_de_config_de_dieta_paciente_la_subida_si_borraria_las_dietas_de_la_web(): void
    {
        // El contraste que ata el test anterior a la configuración (WEB-5.3b).
        $this->assertArrayHasKey('dieta_paciente', config('sync_legado.sin_clave_con_altas_del_app'));

        $consulta = \App\Models\Consulta::where('reg_medico', self::REG)->where('numhistoria', 1)->where('nroconsulta', 1)->firstOrFail();
        app(\App\Actions\Consulta\GuardarDietaDeLaConsulta::class)->ejecutar($consulta, null, 'TEXTO WEB', null, 'web');

        config(['sync_legado.sin_clave_con_altas_del_app' => ['recipe_detalle' => 'recipes', 'recipes_pareja' => 'recipes_pareja']]);

        $this->subir([['id' => 216, 'tabla' => 'dieta_paciente', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00', 'filas' => []]]);

        $this->assertSame(0, DB::table('dieta_paciente')->where('reg_medico', self::REG)->count(), 'la dieta de la web se perdió');
    }

    /**
     * **WEB-5.4**: las cuatro tablas de laboratorio y estudios tienen **clave primaria** en el PowerBuilder (`examen_paciente`
     * `(nrohistoria, nroconsulta, codeexamen)`, `examen_obs` `(numhistoria, numconsulta)`, `radiologia_paciente`
     * `(nrohistoria, nroconsulta, coderadio)` y `radiologia_obs` `(numhistoria, numconsulta)`) y los tres triggers de fila
     * (`tr_ddrsync_<tabla>_ins/_upd/_del` en `quitar-registro-cambios.sql`): el escritorio las sube **fila por fila** y no con el
     * reemplazo de la tabla entera. Ninguna entra a `sin_clave_con_altas_del_app`; lo que sube y borra de **otra** consulta no toca lo que
     * creó la web. Lo que sí pasa y queda fijado (trampa 75): si sube **la misma clave**, su `UPDATE` pisa lo que la web editó.
     */
    public function test_la_subida_fila_por_fila_de_laboratorio_y_estudios_no_toca_lo_que_creo_la_web(): void
    {
        $this->assertSame([], array_intersect(
            ['examen_paciente', 'examen_obs', 'radiologia_paciente', 'radiologia_obs'],
            array_keys(config('sync_legado.sin_clave_con_altas_del_app')),
        ), 'las cuatro tienen clave: se suben por fila y no se reemplazan enteras');

        $consulta = \App\Models\Consulta::where('reg_medico', self::REG)->where('numhistoria', 1)->where('nroconsulta', 1)->firstOrFail();
        DB::table('examenes')->insert([
            ['reg_medico' => self::REG, 'codeexamen' => '001', 'examen' => 'GLICEMIA', 'codetipo' => '001'],
            ['reg_medico' => self::REG, 'codeexamen' => '099', 'examen' => 'MAMOGRAFIA', 'codetipo' => '002'],
        ]);

        // Lo que escribe la web, con las Actions reales (cada fila deja su `created` con `source = web`). Desde O-29 cada
        // examen entra con su alta inmediata y el resumen con su botón: la orden ya no se guarda entera.
        app(\App\Actions\Consulta\GuardarOrdenDeLaboratorio::class)->agregar($consulta, ['001'], null, 'web');
        app(\App\Actions\Consulta\GuardarOrdenDeLaboratorio::class)->guardarResumen($consulta, 'OBS WEB', null, 'web');
        app(\App\Actions\Consulta\GuardarSolicitudDeEstudios::class)->agregar($consulta, ['099'], null, 'web');
        app(\App\Actions\Consulta\GuardarSolicitudDeEstudios::class)->guardarResumen($consulta, 'RESUMEN WEB', null, 'web');

        // El escritorio crea otra consulta (1|2), le sube sus filas y después las borra.
        $fecha = '2026-10-02 10:00:00';
        $this->subir([
            ['id' => 220, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['numhistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => '2026-10-02']],
            ['id' => 221, 'tabla' => 'examen_paciente', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2', 'codeexamen' => '001'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'codeexamen' => '001']],
            ['id' => 222, 'tabla' => 'examen_obs', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['numhistoria' => '1', 'numconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'numconsulta' => 2, 'observacion' => 'DEL ESCRITORIO']],
            ['id' => 223, 'tabla' => 'radiologia_paciente', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2', 'coderadio' => '099'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'coderadio' => '099', 'nroopcion' => 1, 'orden' => 84]],
            ['id' => 224, 'tabla' => 'radiologia_obs', 'op' => 'I', 'fecha' => $fecha,
             'clave' => ['numhistoria' => '1', 'numconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'numconsulta' => 2, 'observacion' => 'DEL ESCRITORIO']],
        ]);

        $this->assertSame(1, DB::table('examen_paciente')->where('reg_medico', self::REG)->where('nroconsulta', 2)->count());
        $this->assertSame(84, (int) DB::table('radiologia_paciente')->where('reg_medico', self::REG)->where('nroconsulta', 2)->value('orden'));

        $this->subir([
            ['id' => 225, 'tabla' => 'examen_paciente', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2', 'codeexamen' => '001']],
            ['id' => 226, 'tabla' => 'examen_obs', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['numhistoria' => '1', 'numconsulta' => '2']],
            ['id' => 227, 'tabla' => 'radiologia_paciente', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2', 'coderadio' => '099']],
            ['id' => 228, 'tabla' => 'radiologia_obs', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => ['numhistoria' => '1', 'numconsulta' => '2']],
        ]);

        foreach (['examen_paciente', 'radiologia_paciente', 'examen_obs', 'radiologia_obs'] as $tabla) {
            $this->assertSame(1, DB::table($tabla)->where('reg_medico', self::REG)->count(), $tabla . ': quedó sólo la fila de la web');
        }
        $this->assertSame('OBS WEB', DB::table('examen_obs')->where('reg_medico', self::REG)->where('numconsulta', 1)->value('observacion'));
        $this->assertSame('RESUMEN WEB', DB::table('radiologia_obs')->where('reg_medico', self::REG)->where('numconsulta', 1)->value('observacion'));
        $this->assertSame('001', trim(DB::table('examen_paciente')->where('reg_medico', self::REG)->where('nroconsulta', 1)->value('codeexamen')));

        // La misma clave: el UPDATE del escritorio pisa lo que la web editó (no consulta `sync_changes`). Límite conocido, trampa 75.
        $this->subir([['id' => 229, 'tabla' => 'examen_obs', 'op' => 'U', 'fecha' => '2026-10-02 12:00:00',
            'clave' => ['numhistoria' => '1', 'numconsulta' => '1'], 'fila' => ['numhistoria' => 1, 'numconsulta' => 1, 'observacion' => 'PISADA POR EL ESCRITORIO']]]);
        $this->assertSame('PISADA POR EL ESCRITORIO', DB::table('examen_obs')->where('reg_medico', self::REG)->where('numconsulta', 1)->value('observacion'));
    }

    public function test_la_subida_fila_por_fila_de_los_catalogos_clinicos_no_toca_las_filas_que_creo_la_web(): void
    {
        // WEB-8.1b: diagnosticos, motivos_consulta, examenes, tipos_examenes y doctores tienen clave primaria en el PowerBuilder
        // (los tres triggers de fila): se suben por clave y NO necesitan `sin_clave_con_altas_del_app` (trampa 60). Si alguna
        // pasara a ser de sentencia (`op = T`), este test falla en la primera aserción.
        $this->assertSame([], array_intersect(
            ['diagnosticos', 'motivos_consulta', 'examenes', 'tipos_examenes', 'doctores', 'especial'],
            array_keys(config('sync_legado.sin_clave_con_altas_del_app')),
        ));

        DB::table('especial')->insert(['reg_medico' => self::REG, 'codeespecial' => '002', 'especialidad' => 'CARDIOLOGIA']);

        // Lo que escribe la web, con las Actions reales (cada una deja su `created` con `source = web`).
        $web = [
            [app(\App\Actions\Catalogos\CrearDiagnosticoEnElCatalogo::class)->ejecutar($this->medico, [self::REG], 'DX WEB')[0], 'diagnosticos', 'codediagnostico'],
            [app(\App\Actions\Catalogos\CrearMotivoEnElCatalogo::class)->ejecutar($this->medico, [self::REG], 'MOTIVO WEB')[0], 'motivos_consulta', 'codemotivo'],
            [app(\App\Actions\Catalogos\CrearTipoDeExamenEnElCatalogo::class)->ejecutar(self::REG, [self::REG], 'TIPO WEB')[0], 'tipos_examenes', 'codetipo'],
            [app(\App\Actions\Catalogos\CrearExamenEnElCatalogo::class)->ejecutar(self::REG, [self::REG], 'EXAMEN WEB', null)[0], 'examenes', 'codeexamen'],
            [app(\App\Actions\Catalogos\CrearDoctorEnElCatalogo::class)->ejecutar(self::REG, [self::REG], ['apellidos' => 'WEB', 'nombres' => 'DOC', 'codeespecial' => '002'])[0], 'doctores', 'cedula'],
        ];

        // El escritorio crea otra fila de cada una (clave 900) y después borra esa misma.
        $fecha = '2026-10-02 10:00:00';
        $cambios = [];
        $n = 300;
        foreach ([['diagnosticos', 'codediagnostico', 'descripcion'], ['motivos_consulta', 'codemotivo', 'descripcion'], ['tipos_examenes', 'codetipo', 'tipo'],
            ['examenes', 'codeexamen', 'examen'], ['doctores', 'cedula', 'apellidos']] as [$tabla, $clave, $texto]) {
            $cambios[] = ['id' => $n++, 'tabla' => $tabla, 'op' => 'I', 'fecha' => $fecha, 'clave' => [$clave => '900'], 'fila' => [$clave => '900', $texto => 'DEL ESCRITORIO']];
        }
        $this->subir($cambios);

        foreach ($web as [$fila, $tabla]) {
            $this->assertNotNull(DB::table($tabla)->find($fila->id), "{$tabla}: la fila de la web sigue después de la subida del escritorio");
        }
        $this->assertSame(1, DB::table('diagnosticos')->where('reg_medico', self::REG)->where('descripcion', 'DEL ESCRITORIO')->count());

        $borrados = [];
        foreach ($cambios as $c) {
            $borrados[] = ['id' => $c['id'] + 100, 'tabla' => $c['tabla'], 'op' => 'D', 'fecha' => '2026-10-02 11:00:00', 'clave' => $c['clave']];
        }
        $this->subir($borrados);

        foreach ($web as [$fila, $tabla]) {
            $this->assertNotNull(DB::table($tabla)->find($fila->id), "{$tabla}: ni el borrado de otra clave toca la fila de la web");
        }
        $this->assertSame(0, DB::table('doctores')->where('reg_medico', self::REG)->where('apellidos', 'DEL ESCRITORIO')->count());
    }

    /**
     * **WEB-8.4**: `formato_print` NO tiene clave primaria en el PowerBuilder (sólo `CREATE UNIQUE INDEX primarykey … (codigo)`) y su registro de
     * cambios es de sentencia (`tr_ddrsync_formato_print`, `op = T`, sin los `_ins/_upd/_del`): la sube ENTERA y el reemplazo borra todo lo del
     * médico. Las plantillas que creó la web (el `created` de cada fila, hecho por la Action real) se conservan; lo que nadie del app creó se reemplaza.
     */
    public function test_reemplazar_formato_print_conserva_las_plantillas_que_creo_la_web_y_reemplaza_el_resto(): void
    {
        $vieja = \App\Models\FormatoPrint::create(['reg_medico' => self::REG, 'codigo' => '0001', 'tipo' => 'EV', 'titulo' => 'VIEJA DEL ESCRITORIO', 'descripcion' => 'texto']);
        $deLaWeb = app(\App\Actions\Catalogos\CrearPlantillaDeTexto::class)->ejecutar(self::REG, ['tipo' => 'IM', 'titulo' => 'De la web', 'descripcion' => 'texto web'], null, 'web');

        $this->subir([['id' => 901, 'tabla' => 'formato_print', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00',
            'filas' => [['codigo' => '0007', 'titulo' => 'NUEVA DEL ESCRITORIO', 'descripcion' => 'texto nuevo', 'tipo' => 'DI']]]]);

        $this->assertEqualsCanonicalizing(
            ['DE LA WEB', 'NUEVA DEL ESCRITORIO'],
            DB::table('formato_print')->where('reg_medico', self::REG)->pluck('titulo')->all(),
            'la de la web se conserva; la vieja que el escritorio ya no tiene se va y la nueva entra',
        );
        $this->assertNotNull(\App\Models\FormatoPrint::find($deLaWeb->id));
        $this->assertNull(\App\Models\FormatoPrint::find($vieja->id));
    }

    public function test_sin_la_linea_de_config_de_formato_print_la_subida_si_borraria_las_plantillas_de_la_web(): void
    {
        // El contraste que ata el test anterior a la configuración (WEB-8.4).
        $this->assertArrayHasKey('formato_print', config('sync_legado.sin_clave_con_altas_del_app'));

        app(\App\Actions\Catalogos\CrearPlantillaDeTexto::class)->ejecutar(self::REG, ['tipo' => 'EV', 'titulo' => 'De la web', 'descripcion' => 'texto'], null, 'web');

        config(['sync_legado.sin_clave_con_altas_del_app' => ['recipe_detalle' => 'recipes', 'recipes_pareja' => 'recipes_pareja']]);

        $this->subir([['id' => 902, 'tabla' => 'formato_print', 'op' => 'T', 'fecha' => '2026-10-02 10:00:00', 'filas' => []]]);

        $this->assertSame(0, DB::table('formato_print')->where('reg_medico', self::REG)->count(), 'la plantilla de la web se perdió');
    }
}
