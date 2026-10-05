<?php

namespace Tests\Feature;

use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Carga inicial completa del escritorio PowerBuilder (botón "Sincronización completa").
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: ver SyncCredencialTest.
 *
 * Lo que fijan estos tests:
 *  - solo se carga sobre un médico sin datos (primera carga), y una sola vez;
 *  - una carga cortada se retoma, y reenviar un lote NO duplica filas;
 *  - solo entran las tablas de la lista blanca, y lo que no se guarda se informa;
 *  - pacientes queda como lo ve el app (paciente + relación con el médico + historia), sin perder
 *    los que no tienen cédula.
 */
class CargaInicialTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    private function crearMedico(string $regMedico): Medico
    {
        $user = User::create([
            'name'     => 'Médico ' . $regMedico,
            'email'    => $regMedico . '-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Médico',
            'lastname'   => 'De Prueba',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function enviar(string $accion, array $cuerpo)
    {
        return $this->postJson("/api/sync/carga-inicial/{$accion}", $cuerpo, ['X-API-KEY' => self::API_KEY]);
    }

    private function iniciar(string $regMedico, array $tablas)
    {
        return $this->enviar('iniciar', ['reg_medico' => $regMedico, 'tablas' => $tablas]);
    }

    private function paciente(int $historia, ?string $cedula, string $nombre = 'ANA'): array
    {
        return [
            'nac' => 'V', 'cedula' => $cedula, 'apellidos' => 'PRUEBA', 'nombres' => $nombre, 'sexo' => 'F',
            'fnacimiento' => '1980-01-02', 'numhistoria' => $historia, 'codesegemp' => '00',
        ];
    }

    /* ------------------------------------------------------------------ */

    public function test_rechaza_un_medico_que_no_esta_registrado(): void
    {
        $this->iniciar('carga-no-existe', ['pacientes' => 1])
            ->assertStatus(404)
            ->assertJson(['ok' => false, 'error' => 'medico_no_registrado']);
    }

    public function test_inicia_sobre_medico_limpio_con_pacientes_primero_y_solo_tablas_permitidas(): void
    {
        $this->crearMedico('carga-t-001');

        $r = $this->iniciar('carga-t-001', [
            'recipes'  => 10,
            'PACIENTES' => 5,        // el escritorio puede mandar mayúsculas
            'users'    => 3,         // existe en el API pero NO es del legado
            'pbcatcol' => 2,         // catálogo interno de PowerBuilder
            'cola'     => 0,
        ])->assertOk();

        $r->assertJson(['ok' => true, 'estado' => 'en_curso', 'retomada' => 0, 'orden' => 'pacientes,cola,recipes']);
        $r->assertJson(['tablas' => ['pacientes' => 0, 'cola' => 0, 'recipes' => 0]]);
        $this->assertSame('users,pbcatcol', $r->json('ignoradas'));
    }

    public function test_rechaza_la_carga_si_el_medico_ya_tiene_datos(): void
    {
        $this->crearMedico('carga-t-002');
        DB::table('cola')->insert(['reg_medico' => 'carga-t-002', 'fecha' => '2026-01-01', 'hora_ini' => '08:00:00']);

        $this->iniciar('carga-t-002', ['cola' => 1])
            ->assertStatus(409)
            ->assertJson(['error' => 'medico_con_datos']);
    }

    public function test_dc_venoso_acepta_numeros_y_letras_del_legado(): void
    {
        // En el legado esa columna es numeric(5,2) en unos consultorios y char en otros ('N'): con DECIMAL el
        // lote entero fallaba con "Incorrect decimal value". Ahora se guarda tal cual.
        $this->crearMedico('carga-t-005');
        $carga = $this->iniciar('carga-t-005', ['eco_obstetrico' => 3])->json('carga_id');

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'eco_obstetrico', 'desde' => 0, 'filas' => [
            ['historia' => 1, 'consulta' => 1, 'dc_venoso' => '1.25'],
            ['historia' => 2, 'consulta' => 1, 'dc_venoso' => 'N'],
            ['historia' => 3, 'consulta' => 1, 'dc_venoso' => null],
        ]])->assertOk()->assertJson(['recibidas' => 3]);

        $valores = DB::table('eco_obstetrico')->where('reg_medico', 'carga-t-005')->orderBy('historia')->pluck('dc_venoso')->all();
        $this->assertSame(['1.25', 'N', null], array_map(fn ($v) => $v === null ? null : (string) $v, $valores));
    }

    public function test_evolucion_no_cuenta_como_dato_previo(): void
    {
        $this->crearMedico('carga-t-003');
        DB::table('evolucion')->insert(['reg_medico' => 'carga-t-003', 'clave' => 1]);

        $this->iniciar('carga-t-003', ['evolucion' => 1])->assertOk();
    }

    public function test_pacientes_quedan_como_los_ve_el_app_y_los_sin_cedula_no_se_pierden(): void
    {
        $medico = $this->crearMedico('carga-t-004');
        $carga = $this->iniciar('carga-t-004', ['pacientes' => 3])->json('carga_id');

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'pacientes', 'desde' => 0, 'filas' => [
            $this->paciente(1, 'CT004-1'),
            $this->paciente(2, null, 'SIN CEDULA UNO'),
            $this->paciente(3, '', 'SIN CEDULA DOS'),
        ]])->assertOk()->assertJson(['recibidas' => 3, 'duplicado' => 0]);

        $historias = Historia::where('medico_id', $medico->id)->orderBy('numhistoria')->get();
        $this->assertCount(3, $historias, 'una historia por paciente del escritorio');
        $this->assertCount(3, $historias->pluck('paciente_id')->unique(), 'los sin cédula NO se mezclan');
        $this->assertSame(3, MedicoPaciente::where('medico_id', $medico->id)->count());
        $this->assertSame('SIN CEDULA DOS', Paciente::find($historias[2]->paciente_id)->nombres);
    }

    public function test_reenviar_un_lote_no_duplica_y_un_hueco_se_rechaza(): void
    {
        $this->crearMedico('carga-t-005');
        $carga = $this->iniciar('carga-t-005', ['antece_paciente' => 3])->json('carga_id');

        $lote = ['carga_id' => $carga, 'tabla' => 'antece_paciente', 'desde' => 0, 'filas' => [
            ['numhistoria' => 1, 'codeantecedente' => 'A1', 'detalles' => 'uno'],
            ['numhistoria' => 1, 'codeantecedente' => 'A2', 'detalles' => 'dos'],
        ]];

        $this->enviar('lote', $lote)->assertOk()->assertJson(['recibidas' => 2, 'duplicado' => 0]);
        // Corte de red: el escritorio no vio la respuesta y reenvía el mismo lote.
        $this->enviar('lote', $lote)->assertOk()->assertJson(['recibidas' => 2, 'duplicado' => 1]);
        $this->assertSame(2, DB::table('antece_paciente')->where('reg_medico', 'carga-t-005')->count());

        // Salto hacia adelante: el API dice desde dónde seguir.
        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'antece_paciente', 'desde' => 5, 'filas' => [
            ['numhistoria' => 2, 'codeantecedente' => 'A1'],
        ]])->assertStatus(409)->assertJson(['error' => 'posicion_incorrecta', 'esperado' => 2]);
    }

    public function test_insercion_generica_pone_reg_medico_e_informa_columnas_que_no_existen(): void
    {
        $this->crearMedico('carga-t-006');
        $carga = $this->iniciar('carga-t-006', ['antece_paciente' => 1])->json('carga_id');

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'antece_paciente', 'desde' => 0, 'filas' => [
            ['id' => 999999, 'reg_medico' => 'OTRO', 'numhistoria' => 7, 'codeantecedente' => 'X', 'columna_rara' => 1],
        ]])->assertOk();

        $fila = DB::table('antece_paciente')->where('reg_medico', 'carga-t-006')->first();
        $this->assertSame(7, (int) $fila->numhistoria);
        $this->assertNotEquals(999999, $fila->id, 'el id lo pone el API');
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', 'OTRO')->count());

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'antece_paciente', 'desde' => 1, 'filas' => []]);
        $this->enviar('finalizar', ['carga_id' => $carga])
            ->assertOk()
            ->assertJson(['columnas_ignoradas' => 'antece_paciente:columna_rara']);
    }

    public function test_los_textos_vacios_llegan_vacios_y_no_como_null(): void
    {
        // Laravel convierte "" en null (ConvertEmptyStringsToNull). `imagen_pacientes.imagen` es
        // NOT NULL y en el escritorio casi todas las filas la tienen vacía: la carga se cortaba ahí.
        $this->crearMedico('carga-t-009');
        $carga = $this->iniciar('carga-t-009', ['imagen_pacientes' => 1])->json('carga_id');

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'imagen_pacientes', 'desde' => 0, 'filas' => [
            ['nrohistoria' => 1, 'imagen' => '', 'imagen2' => ''],
        ]])->assertOk();

        $fila = DB::table('imagen_pacientes')->where('reg_medico', 'carga-t-009')->first();
        $this->assertSame('', $fila->imagen);
        $this->assertSame('', $fila->imagen2);
    }

    public function test_acepta_las_tablas_del_legado_que_faltaban_y_la_columna_cantidad(): void
    {
        $this->crearMedico('carga-t-010');
        $r = $this->iniciar('carga-t-010', ['reposo_paciente' => 1, 'pre_natal_desarrollo_fino' => 1])->assertOk();
        $this->assertSame('', $r->json('ignoradas'));
        $carga = $r->json('carga_id');

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'reposo_paciente', 'desde' => 0, 'filas' => [
            ['nrohistoria' => 3, 'nroconsulta' => 1, 'codereposo' => 'A', 'fdesde' => '2020-01-02', 'numdias' => 5],
        ]])->assertOk();
        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'pre_natal_desarrollo_fino', 'desde' => 0, 'filas' => [
            ['historia' => 3, 'fecha' => '2020-01-02', 'gesta_clave' => 1, 'cantidad' => 2],
        ]])->assertOk();

        $this->assertSame(5, (int) DB::table('reposo_paciente')->where('reg_medico', 'carga-t-010')->value('numdias'));
        $this->assertSame(2, (int) DB::table('pre_natal_desarrollo_fino')->where('reg_medico', 'carga-t-010')->value('cantidad'));

        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk()->assertJson(['columnas_ignoradas' => '']);
    }

    public function test_evolucion_no_sube_credenciales_ni_logo(): void
    {
        $this->crearMedico('carga-t-007');
        $carga = $this->iniciar('carga-t-007', ['evolucion' => 1])->json('carga_id');

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'evolucion', 'desde' => 0, 'filas' => [[
            'clave' => 1, 'especialidad' => 'GINECOLOGIA', 'contrasena' => 'secreta',
            'sms_user' => 'u', 'sms_clave' => 'c', 'logo' => 'C:\\logo.bmp',
        ]]])->assertOk();

        $evo = DB::table('evolucion')->where('reg_medico', 'carga-t-007')->first();
        $this->assertSame('GINECOLOGIA', $evo->especialidad);
        $this->assertNull($evo->contrasena);
        $this->assertNull($evo->sms_clave);
        $this->assertNull($evo->logo);
    }

    public function test_una_carga_cortada_se_retoma_y_al_completarse_no_se_repite(): void
    {
        $this->crearMedico('carga-t-008');
        $carga = $this->iniciar('carga-t-008', ['antece_paciente' => 2])->json('carga_id');

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'antece_paciente', 'desde' => 0, 'filas' => [
            ['numhistoria' => 1, 'codeantecedente' => 'A1'],
        ]])->assertOk();

        // Incompleta: no se puede cerrar.
        $this->enviar('finalizar', ['carga_id' => $carga])
            ->assertStatus(409)
            ->assertJson(['error' => 'carga_incompleta', 'pendientes' => ['antece_paciente' => 1]]);

        // El escritorio se cerró; al volver, retoma la MISMA carga desde la fila 1.
        $this->iniciar('carga-t-008', ['antece_paciente' => 2])
            ->assertOk()
            ->assertJson(['carga_id' => $carga, 'retomada' => 1, 'tablas' => ['antece_paciente' => 1]]);

        $this->enviar('lote', ['carga_id' => $carga, 'tabla' => 'antece_paciente', 'desde' => 1, 'filas' => [
            ['numhistoria' => 1, 'codeantecedente' => 'A2'],
        ]])->assertOk();

        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk()->assertJson(['estado' => 'completa', 'filas' => 2]);

        $this->iniciar('carga-t-008', ['antece_paciente' => 2])
            ->assertStatus(409)
            ->assertJson(['error' => 'carga_ya_completa']);
    }

    public function test_sin_credencial_no_entra(): void
    {
        $this->postJson('/api/sync/carga-inicial/iniciar', ['reg_medico' => 'x', 'tablas' => []])
            ->assertStatus(401);
    }
}
