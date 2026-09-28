<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Documentos que el app solo imprime (ROADMAP.md Paso 25): constancias, reposos, referencias e
 * informes, con los catálogos que necesitan, bajan por `sync-app-data`.
 *
 * Los datos entran como en la realidad: por la carga inicial y la subida de cambios del escritorio.
 */
class SyncReportesTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    private function escritorio(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY])->assertOk();
    }

    /** Un médico con su carga inicial completa: historia 1, consulta 1|1 y un documento de cada tipo. */
    private function medicoConDocumentos(string $reg, string $motivo = 'CARDIOLOGIA'): User
    {
        $user = User::create(['name' => 'Médico', 'email' => "{$reg}@example.com", 'password' => Hash::make('x')]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => 'Reportes', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        $filas = [
            'pacientes' => [['numhistoria' => 1, 'cedula' => "C-{$reg}", 'apellidos' => 'PRUEBA', 'nombres' => 'ANA', 'nac' => 'V', 'sexo' => 'F']],
            'consultas' => [['numhistoria' => 1, 'nroconsulta' => 1, 'fecha' => '2026-03-10']],
            'constancia_obs' => [['numhistoria' => 1, 'numconsulta' => 1, 'observacion01' => 'Asistió a consulta']],
            'reposo_paciente' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'fdesde' => '2026-03-10', 'numdias' => 3, 'obser_reposo' => 'Reposo absoluto']],
            'referencia' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'ceduladoctor' => 12345678, 'referencia' => $motivo]],
            'informe' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'para' => 'A quien pueda interesar', 'descripcion' => 'Paciente sana', 'fe_cha' => '2026-03-10']],
            'diagnostico_paciente' => [['nrohistoria' => 1, 'nroconsulta' => 1, 'codediagnostico' => 'D01', 'orden' => 1]],
            'diagnosticos' => [['codediagnostico' => 'D01', 'descripcion' => 'EMBARAZO NORMAL']],
            'doctores' => [['cedula' => 12345678, 'apellidos' => 'PEREZ', 'nombres' => 'JUAN', 'codeespecial' => 'CAR']],
            'especial' => [['codeespecial' => 'CAR', 'especialidad' => 'CARDIOLOGIA']],
        ];
        $carga = $this->escritorio('carga-inicial/iniciar', [
            'reg_medico' => $reg,
            'tablas' => array_map('count', $filas),
        ])->json('carga_id');
        foreach ($filas as $tabla => $lote) {
            $this->escritorio('carga-inicial/lote', ['carga_id' => $carga, 'tabla' => $tabla, 'desde' => 0, 'filas' => $lote]);
        }
        $this->escritorio('carga-inicial/finalizar', ['carga_id' => $carga]);

        return $user;
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_los_documentos_de_la_consulta_y_sus_catalogos_bajan_al_telefono(): void
    {
        Sanctum::actingAs($this->medicoConDocumentos('rep-a-' . uniqid()), ['*'], 'api');

        $r = $this->sync();

        $this->assertSame('Asistió a consulta', $r->json('constancia_obs.0.observacion01'));
        $this->assertSame(1, $r->json('constancia_obs.0.numhistoria'));
        $this->assertSame(3, $r->json('reposo_paciente.0.numdias'));
        $this->assertStringStartsWith('2026-03-10', $r->json('reposo_paciente.0.fdesde'));
        $this->assertSame('Paciente sana', $r->json('informe.0.descripcion'));
        $this->assertSame('D01', $r->json('diagnostico_paciente.0.codediagnostico'));
        $this->assertSame('EMBARAZO NORMAL', $r->json('diagnosticos.0.descripcion'));
        $this->assertSame('CARDIOLOGIA', $r->json('especial.0.especialidad'));
        // Las dos cédulas llegan como número, para que el cliente las cruce sin normalizar.
        $this->assertSame(12345678, $r->json('referencia.0.ceduladoctor'));
        $this->assertSame(12345678, $r->json('doctores.0.cedula'));
    }

    public function test_la_descarga_completa_no_manda_las_plantillas_vacias_que_crea_el_legado(): void
    {
        $reg = 'rep-e-' . uniqid();
        Sanctum::actingAs($this->medicoConDocumentos($reg), ['*'], 'api');
        // Consulta 2: lo que deja el legado con solo abrirla (constancia sin observación, informe ".").
        $this->escritorio('cambios/subir', ['reg_medico' => $reg, 'cambios' => [
            ['id' => 1, 'tabla' => 'consultas', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'nroconsulta' => 2, 'fecha' => '2026-10-02']],
            ['id' => 2, 'tabla' => 'constancia_obs', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '1', 'numconsulta' => '2'], 'fila' => ['numhistoria' => 1, 'numconsulta' => 2, 'observacion01' => '  ']],
            ['id' => 3, 'tabla' => 'informe', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'para' => 'INFORME MÉDICO', 'descripcion' => '.']],
            ['id' => 4, 'tabla' => 'reposo_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '2'], 'fila' => ['nrohistoria' => 1, 'nroconsulta' => 2, 'codereposo' => 'M', 'fdesde' => '2026-10-02', 'numdias' => 1]],
        ]]);

        $completa = $this->sync();
        $this->assertSame([1], collect($completa->json('constancia_obs'))->pluck('numconsulta')->all());
        $this->assertSame([1], collect($completa->json('informe'))->pluck('nroconsulta')->all());
        $this->assertSame([1], collect($completa->json('reposo_paciente'))->pluck('nroconsulta')->all());

        // Por delta sí bajan: si un documento se vació en el escritorio, el teléfono tiene que saberlo.
        $delta = $this->sync(['since' => '2000-01-01T00:00:00Z']);
        $this->assertCount(2, $delta->json('constancia_obs'));
        $this->assertCount(2, $delta->json('informe'));
        $this->assertCount(2, $delta->json('reposo_paciente'));
    }

    public function test_un_medico_no_recibe_los_documentos_de_otro_con_el_mismo_numero_de_historia(): void
    {
        $this->medicoConDocumentos('rep-b-' . uniqid(), 'DEL OTRO MEDICO');
        Sanctum::actingAs($this->medicoConDocumentos('rep-c-' . uniqid()), ['*'], 'api');

        $r = $this->sync();

        foreach (['constancia_obs', 'reposo_paciente', 'referencia', 'informe', 'diagnostico_paciente', 'diagnosticos', 'doctores', 'especial'] as $tabla) {
            $this->assertCount(1, $r->json($tabla), $tabla);
        }
        $this->assertSame('CARDIOLOGIA', $r->json('referencia.0.referencia'));
    }

    public function test_por_delta_solo_baja_lo_que_cambio_y_se_avisa_lo_que_borro_el_escritorio(): void
    {
        $reg = 'rep-d-' . uniqid();
        Sanctum::actingAs($this->medicoConDocumentos($reg), ['*'], 'api');
        $since = $this->sync()->json('synced_at');
        $this->travel(5)->seconds();

        $this->escritorio('cambios/subir', ['reg_medico' => $reg, 'cambios' => [
            ['id' => 1, 'tabla' => 'reposo_paciente', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '1'],
             'fila' => ['nrohistoria' => 1, 'nroconsulta' => 1, 'fdesde' => '2026-03-10', 'numdias' => 5]],
            ['id' => 2, 'tabla' => 'informe', 'op' => 'D', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['nrohistoria' => '1', 'nroconsulta' => '1']],
        ]]);
        $informe = DB::table('sync_changes')->where('reg_medico', $reg)->where('table_name', 'informe')->value('record_id');

        $r = $this->sync(['since' => $since]);

        $this->assertSame(5, $r->json('reposo_paciente.0.numdias'));
        $this->assertSame([], $r->json('constancia_obs'));
        $this->assertSame([], $r->json('informe'));
        $this->assertContains(
            ['table_name' => 'informe', 'record_id' => $informe],
            collect($r->json('eliminados'))->map(fn ($e) => ['table_name' => $e['table_name'], 'record_id' => $e['record_id']])->all(),
        );
    }
}
