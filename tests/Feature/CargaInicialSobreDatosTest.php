<?php

namespace Tests\Feature;

use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncTraslado;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Carga inicial sobre un consultorio que ya tiene datos** (modo mezcla).
 *
 * La regla: el escritorio es la fuente absoluta. Conserva sus números (el de historia no puede cambiar:
 * ya está impreso) y el registro del API que ocupaba ese número se aparta y recibe el libre siguiente al
 * cerrar la carga. Lo que se movió queda en `sync_traslados` con su copia.
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: ver SyncCredencialTest.
 */
class CargaInicialSobreDatosTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    /* ------------------------------------------------------------------ */
    /* Ayudantes                                                          */
    /* ------------------------------------------------------------------ */

    private function crearMedico(string $regMedico): Medico
    {
        $user = User::create([
            'name'     => 'Médico ' . $regMedico,
            'email'    => $regMedico . '-' . substr(uniqid(), -6) . '@example.com',
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

    private function lote(int $carga, string $tabla, int $desde, array $filas)
    {
        return $this->enviar('lote', ['carga_id' => $carga, 'tabla' => $tabla, 'desde' => $desde, 'filas' => $filas]);
    }

    /** Un paciente del escritorio, con las columnas del legado. */
    private function paciente(int $historia, ?string $cedula, string $nombre = 'ANA'): array
    {
        return [
            'nac' => 'V', 'cedula' => $cedula, 'apellidos' => 'PRUEBA', 'nombres' => $nombre, 'sexo' => 'F',
            'fnacimiento' => '1980-01-02', 'numhistoria' => $historia, 'codesegemp' => '00',
        ];
    }

    /**
     * Lo que dejó la web ANTES de la carga: paciente + historia + vínculo, con la historia sin
     * `clave_escritorio` (nunca vino del escritorio).
     */
    private function webPaciente(string $reg, int $medicoId, int $numhistoria, ?string $cedula): int
    {
        $ahora = now();

        $pacienteId = DB::table('pacientes')->insertGetId([
            'cedula' => $cedula, 'nombres' => 'PACIENTE', 'apellidos' => 'DE LA WEB',
            'reg_medico' => $reg, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        DB::table('historias')->insert([
            'reg_medico' => $reg, 'numhistoria' => (string) $numhistoria,
            'paciente_id' => $pacienteId, 'medico_id' => $medicoId,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id' => $medicoId, 'paciente_id' => $pacienteId,
            'numhistoria' => (string) $numhistoria, 'reg_medico' => $reg,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        return $pacienteId;
    }

    private function webConsulta(string $reg, int $historia, int $consulta, string $texto = 'WEB'): int
    {
        return DB::table('consultas')->insertGetId([
            'reg_medico' => $reg, 'numhistoria' => $historia, 'nroconsulta' => $consulta,
            'fecha' => '2026-10-20', 'enfermedadactual' => $texto,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function consulta(int $historia, int $consulta, string $texto): array
    {
        return [
            'numhistoria' => $historia, 'nroconsulta' => $consulta,
            'fecha' => '2026-10-20', 'enfermedadactual' => $texto,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* 1. La consulta de la web se va al final; la del escritorio manda    */
    /* ------------------------------------------------------------------ */

    public function test_la_consulta_que_ocupaba_el_numero_del_escritorio_se_mueve_al_final(): void
    {
        $medico = $this->crearMedico('mezcla-t-001');
        $pacienteId = $this->webPaciente('mezcla-t-001', $medico->id, 1, 'V-11111111');
        $consultaWeb = $this->webConsulta('mezcla-t-001', 1, 1, 'LO DE LA WEB');

        $r = $this->iniciar('mezcla-t-001', ['pacientes' => 1, 'consultas' => 1])->assertOk();
        $r->assertJson(['modo' => 'mezcla', 'traslados_pendientes' => 0]);
        $carga = $r->json('carga_id');

        $this->lote($carga, 'pacientes', 0, [$this->paciente(1, 'V-11111111')])->assertOk();
        $this->lote($carga, 'consultas', 0, [$this->consulta(1, 1, 'LO DEL ESCRITORIO')])->assertOk();

        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk()
            ->assertJson(['estado' => 'completa', 'traslados_pendientes' => 0, 'traslados_a_revisar' => 0]);

        // La del escritorio entró con su número, intacta.
        $delEscritorio = DB::table('consultas')->where('reg_medico', 'mezcla-t-001')
            ->where('numhistoria', 1)->where('nroconsulta', 1)->get();
        $this->assertCount(1, $delEscritorio, 'el par del escritorio quedó una sola vez');
        $this->assertSame('LO DEL ESCRITORIO', $delEscritorio->first()->enfermedadactual);

        // La de la web no se perdió: sigue viva, con su id, en el número libre siguiente.
        $deLaWeb = DB::table('consultas')->where('id', $consultaWeb)->first();
        $this->assertNotNull($deLaWeb, 'la consulta de la web no se borró');
        $this->assertSame(2, (int) $deLaWeb->nroconsulta);
        $this->assertSame('LO DE LA WEB', $deLaWeb->enfermedadactual);

        $traslado = SyncTraslado::where('sync_carga_id', $carga)->firstOrFail();
        $this->assertSame('consultas', $traslado->tabla);
        $this->assertSame('consulta_ocupada', $traslado->motivo);
        $this->assertSame('reacomodado', $traslado->estado);
        $this->assertSame('1', $traslado->clave_original);
        $this->assertSame('2', $traslado->clave_final);
        $this->assertSame('LO DE LA WEB', $traslado->copiaDecodificada()['enfermedadactual']);

        $this->assertSame($pacienteId, (int) Historia::where('reg_medico', 'mezcla-t-001')
            ->where('numhistoria', '1')->value('paciente_id'));
    }

    /* ------------------------------------------------------------------ */
    /* 2. La historia del escritorio manda, y se lleva todo lo que cuelga  */
    /* ------------------------------------------------------------------ */

    public function test_el_numero_de_historia_del_escritorio_manda_y_arrastra_su_arbol(): void
    {
        $medico = $this->crearMedico('mezcla-t-002');

        // La web usó la historia 1 para OTRO paciente, con consulta, eco, diagnóstico y cita.
        $pacienteWeb = $this->webPaciente('mezcla-t-002', $medico->id, 1, 'V-AAA');
        $this->webConsulta('mezcla-t-002', 1, 1, 'DE LA WEB');
        $ahora = now();
        DB::table('eco_obstetrico')->insert([
            'reg_medico' => 'mezcla-t-002', 'historia' => 1, 'consulta' => 1, 'dc_venoso' => '1.25',
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('diagnostico_paciente')->insert([
            'reg_medico' => 'mezcla-t-002', 'nrohistoria' => 1, 'nroconsulta' => 1,
            'codediagnostico' => '0001', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('cola')->insert([
            'reg_medico' => 'mezcla-t-002', 'fecha' => '2026-10-20', 'hora_ini' => '08:00:00',
            'numhistoria' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $r = $this->iniciar('mezcla-t-002', ['pacientes' => 1, 'consultas' => 1])->assertOk();
        $this->assertSame('mezcla', $r->json('modo'));
        $carga = $r->json('carga_id');

        // El escritorio trae a OTRO paciente también con la historia 1: es la fuente absoluta.
        $this->lote($carga, 'pacientes', 0, [$this->paciente(1, 'V-BBB', 'OTRO')])->assertOk();
        $this->lote($carga, 'consultas', 0, [$this->consulta(1, 5, 'DEL ESCRITORIO')])->assertOk();
        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk();

        // El paciente del escritorio tiene la historia 1.
        $this->assertSame('OTRO', DB::table('pacientes')->where('id',
            DB::table('historias')->where('reg_medico', 'mezcla-t-002')->where('numhistoria', '1')->value('paciente_id')
        )->value('nombres'));

        // El paciente de la web conserva su ficha, con el número libre siguiente…
        $historiaWeb = Historia::where('reg_medico', 'mezcla-t-002')->where('paciente_id', $pacienteWeb)->firstOrFail();
        $this->assertSame('2', (string) $historiaWeb->numhistoria);

        // …y TODO lo que colgaba de su número se movió con ella, aunque la columna se llame distinto
        // (`numhistoria`, `historia`, `nrohistoria`).
        $consultaWeb = DB::table('consultas')->where('reg_medico', 'mezcla-t-002')
            ->where('enfermedadactual', 'DE LA WEB')->first();
        $this->assertNotNull($consultaWeb, 'la consulta de la web no se perdió');
        $this->assertSame(2, (int) $consultaWeb->numhistoria);
        $this->assertSame(1, (int) $consultaWeb->nroconsulta);
        $this->assertSame(2, (int) DB::table('eco_obstetrico')->where('reg_medico', 'mezcla-t-002')->value('historia'));
        $this->assertSame(2, (int) DB::table('diagnostico_paciente')->where('reg_medico', 'mezcla-t-002')->value('nrohistoria'));
        $this->assertSame(2, (int) DB::table('cola')->where('reg_medico', 'mezcla-t-002')->value('numhistoria'));
        $this->assertSame('2', (string) DB::table('medico_pacientes')
            ->where('reg_medico', 'mezcla-t-002')->where('paciente_id', $pacienteWeb)->value('numhistoria'));

        // La consulta del escritorio quedó intacta en su par.
        $this->assertSame('DEL ESCRITORIO', DB::table('consultas')->where('reg_medico', 'mezcla-t-002')
            ->where('numhistoria', 1)->where('nroconsulta', 5)->value('enfermedadactual'));

        $traslado = SyncTraslado::where('sync_carga_id', $carga)->where('nivel', 'historia')->firstOrFail();
        $this->assertSame('historia_ocupada', $traslado->motivo);
        $this->assertSame('reacomodado', $traslado->estado);
    }

    /* ------------------------------------------------------------------ */
    /* 3. El mismo paciente en los dos lados: adopta el número             */
    /* ------------------------------------------------------------------ */

    public function test_el_mismo_paciente_por_cedula_adopta_el_numero_del_escritorio(): void
    {
        $medico = $this->crearMedico('mezcla-t-003');
        $pacienteId = $this->webPaciente('mezcla-t-003', $medico->id, 1, 'V-CCC');
        $consultaWeb = $this->webConsulta('mezcla-t-003', 1, 1, 'DE LA WEB');

        $r = $this->iniciar('mezcla-t-003', ['pacientes' => 1, 'consultas' => 1])->assertOk();
        $carga = $r->json('carga_id');

        // La misma señora, con la historia 4701 del escritorio y su consulta 1.
        $this->lote($carga, 'pacientes', 0, [$this->paciente(4701, 'V-CCC', 'LA MISMA')])->assertOk();
        $this->lote($carga, 'consultas', 0, [$this->consulta(4701, 1, 'DEL ESCRITORIO')])->assertOk();
        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk();

        // Una sola historia para esa persona, con el número del escritorio.
        $historias = Historia::where('reg_medico', 'mezcla-t-003')->where('paciente_id', $pacienteId)->get();
        $this->assertCount(1, $historias);
        $this->assertSame('4701', (string) $historias->first()->numhistoria);
        $this->assertSame('4701', (string) $historias->first()->clave_escritorio);

        // Su consulta de la web se fue a la historia 4701 y la 1 del escritorio quedó intacta.
        $deLaWeb = DB::table('consultas')->where('id', $consultaWeb)->first();
        $this->assertSame(4701, (int) $deLaWeb->numhistoria);
        $this->assertSame(2, (int) $deLaWeb->nroconsulta, 'el escritorio se quedó con la consulta 1');

        $this->assertSame('DEL ESCRITORIO', DB::table('consultas')->where('reg_medico', 'mezcla-t-003')
            ->where('numhistoria', 4701)->where('nroconsulta', 1)->value('enfermedadactual'));

        $this->assertDatabaseHas('sync_traslados', [
            'sync_carga_id' => $carga, 'motivo' => 'paciente_adoptado', 'estado' => 'reacomodado', 'clave_final' => '4701',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Los catálogos: el escritorio reemplaza y lo de la web queda copia */
    /* ------------------------------------------------------------------ */

    public function test_los_catalogos_del_escritorio_reemplazan_y_lo_reemplazado_queda_en_copia(): void
    {
        $medico = $this->crearMedico('mezcla-t-004');

        // Un dato clínico obliga al modo mezcla (si sólo fueran copias del catálogo, la carga iría limpia).
        $this->webPaciente('mezcla-t-004', $medico->id, 1, 'V-DDD');
        DB::table('diagnosticos')->insert([
            'reg_medico' => 'mezcla-t-004', 'codediagnostico' => '0001', 'descripcion' => 'LO DE LA WEB',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->iniciar('mezcla-t-004', ['pacientes' => 0, 'diagnosticos' => 1])->assertOk();
        $carga = $r->json('carga_id');

        $this->lote($carga, 'diagnosticos', 0, [
            ['codediagnostico' => '0001', 'descripcion' => 'LO DEL ESCRITORIO'],
        ])->assertOk();
        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk();

        $quedan = DB::table('diagnosticos')->where('reg_medico', 'mezcla-t-004')->get();
        $this->assertCount(1, $quedan, 'el catálogo quedó como el del escritorio, sin duplicar');
        $this->assertSame('LO DEL ESCRITORIO', $quedan->first()->descripcion);

        $traslado = SyncTraslado::where('sync_carga_id', $carga)->where('nivel', 'tabla')->firstOrFail();
        $this->assertSame('catalogo_reemplazado', $traslado->motivo);
        $this->assertSame('a_revisar', $traslado->estado);
        $this->assertSame('LO DE LA WEB', $traslado->copiaDecodificada()[0]['descripcion']);
    }

    /* ------------------------------------------------------------------ */
    /* 5. Una carga cortada se retoma sin duplicar los traslados           */
    /* ------------------------------------------------------------------ */

    public function test_una_carga_cortada_se_retoma_y_no_duplica_los_traslados(): void
    {
        $medico = $this->crearMedico('mezcla-t-005');
        $this->webPaciente('mezcla-t-005', $medico->id, 1, 'V-EEE');
        $this->webConsulta('mezcla-t-005', 1, 1, 'DE LA WEB');

        $r = $this->iniciar('mezcla-t-005', ['pacientes' => 2, 'consultas' => 1])->assertOk();
        $carga = $r->json('carga_id');

        $this->lote($carga, 'pacientes', 0, [$this->paciente(1, 'V-FFF')])->assertOk();

        // El escritorio se cerró: al volver retoma la MISMA carga.
        $this->iniciar('mezcla-t-005', ['pacientes' => 2, 'consultas' => 1])
            ->assertOk()
            ->assertJson(['carga_id' => $carga, 'retomada' => 1, 'modo' => 'mezcla']);

        $this->lote($carga, 'pacientes', 1, [$this->paciente(2, 'V-GGG')])->assertOk();
        $this->lote($carga, 'consultas', 0, [$this->consulta(1, 1, 'DEL ESCRITORIO')])->assertOk();
        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk();

        // Un solo apartado por la historia 1 (el que provocó el primer lote), no dos; y nada pendiente.
        $this->assertSame(1, SyncTraslado::where('sync_carga_id', $carga)->where('nivel', 'historia')->count());
        $this->assertSame(0, SyncTraslado::where('sync_carga_id', $carga)->where('nivel', 'consulta')->count());
        $this->assertSame(0, SyncTraslado::where('sync_carga_id', $carga)->where('estado', 'pendiente')->count());
    }

    /* ------------------------------------------------------------------ */
    /* 6. Una fila apartada que ya no existe no inventa nada               */
    /* ------------------------------------------------------------------ */

    public function test_un_traslado_cuya_fila_ya_no_existe_queda_a_revisar_y_no_frena_la_carga(): void
    {
        $medico = $this->crearMedico('mezcla-t-006');
        $this->webPaciente('mezcla-t-006', $medico->id, 1, 'V-HHH');
        $this->webConsulta('mezcla-t-006', 1, 1, 'DE LA WEB');

        $r = $this->iniciar('mezcla-t-006', ['pacientes' => 0, 'consultas' => 1])->assertOk();
        $carga = $r->json('carga_id');
        $this->lote($carga, 'consultas', 0, [$this->consulta(1, 1, 'DEL ESCRITORIO')])->assertOk();

        // Alguien borró la fila antes de cerrar la carga.
        DB::table('consultas')->where('reg_medico', 'mezcla-t-006')->where('nroconsulta', '>=', 900000000)->delete();

        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk()
            ->assertJson(['estado' => 'completa', 'traslados_pendientes' => 0, 'traslados_a_revisar' => 1]);

        $this->assertDatabaseHas('sync_traslados', [
            'sync_carga_id' => $carga, 'estado' => 'a_revisar',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 7. El consultorio limpio no paga nada de esto                       */
    /* ------------------------------------------------------------------ */

    public function test_un_consultorio_limpio_sigue_cargando_en_modo_limpio_sin_traslados(): void
    {
        $this->crearMedico('limpia-t-001');

        $r = $this->iniciar('limpia-t-001', ['pacientes' => 1, 'consultas' => 1])->assertOk();
        $r->assertJson(['modo' => 'limpia', 'traslados_pendientes' => 0]);
        $carga = $r->json('carga_id');

        $this->lote($carga, 'pacientes', 0, [$this->paciente(1, 'V-III')])->assertOk();
        $this->lote($carga, 'consultas', 0, [$this->consulta(1, 1, 'TODO BIEN')])->assertOk();

        $this->enviar('finalizar', ['carga_id' => $carga])->assertOk()
            ->assertJson(['estado' => 'completa', 'traslados' => 0]);

        $this->assertSame(0, SyncTraslado::where('sync_carga_id', $carga)->count());
        $this->assertSame(1, DB::table('consultas')->where('reg_medico', 'limpia-t-001')->count());
    }
}
