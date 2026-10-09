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

        $user = User::create(['name' => 'Médico cambios', 'email' => 'cambios-' . uniqid() . '@example.com', 'password' => Hash::make('x')]);
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
        $this->post_('cambios/estado', ['reg_medico' => 'nadie-' . uniqid()])->assertOk()->assertJson(['carga' => 'ninguna']);

        $r = $this->post_('cambios/estado', ['reg_medico' => self::REG])->assertOk()->assertJson(['carga' => 'completa']);
        $this->assertContains('pacientes', explode(',', $r->json('tablas')));
    }

    public function test_estado_con_credencial_de_equipo_devuelve_el_reg_medico_de_la_credencial(): void
    {
        $token = SyncCredential::generarToken();
        SyncCredential::create([
            'reg_medico' => self::REG, 'machine_label' => 'EQUIPO-' . uniqid(), 'machine_host' => 'PC-TEST',
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
        $this->post_('cambios/estado', ['reg_medico' => 'nadie-' . uniqid()])->assertOk()->assertJson(['medico_nombre' => '']);
    }

    public function test_sin_carga_inicial_completa_no_se_suben_cambios(): void
    {
        $this->post_('cambios/subir', ['reg_medico' => 'nadie-' . uniqid(), 'cambios' => []])
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
        $ana = Paciente::create(['cedula' => 'APP-' . uniqid(), 'nombres' => 'ANA', 'apellidos' => 'APP']);
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

    public function test_estado_devuelve_el_prefijo_del_medico_o_null(): void
    {
        $this->post_('cambios/estado', ['reg_medico' => self::REG])->assertOk()
            ->assertJson(['medico_nombre' => 'Médico Cambios', 'medico_prefix' => null]);

        $this->medico->update(['prefix' => 'Dra']);

        // El nombre no lleva el prefijo: el escritorio los une (Doct) y usa el nombre solo (docti).
        $this->post_('cambios/estado', ['reg_medico' => self::REG])->assertOk()
            ->assertJson(['medico_nombre' => 'Médico Cambios', 'medico_prefix' => 'Dra.']);

        $this->post_('cambios/estado', ['reg_medico' => 'nadie-' . uniqid()])->assertOk()
            ->assertJson(['medico_nombre' => '', 'medico_prefix' => null]);
    }
}
