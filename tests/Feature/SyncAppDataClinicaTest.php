<?php

namespace Tests\Feature;

use App\Http\Requests\SyncAppDataRequest;
use App\Models\AntecePaciente;
use App\Models\Consulta;
use App\Models\DiagnosticoPaciente;
use App\Models\ExamenFisico;
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
 * **A1 de PLAN-MOVIL.md: el camino de edición clínica del sync.**
 *
 * Hasta acá el teléfono sólo podía editar `cola` y `pacientes`; atender una consulta de principio a fin
 * sin la computadora necesita además escribir la enfermedad actual, la evolución, el tratamiento, la
 * evaluación física, los diagnósticos y los antecedentes. Lo que se fija acá es lo que hace delicado el
 * paso:
 *
 *  1. Que esas columnas **entren** y dejen su rastro `source = mobile` con el `reg_medico` de la fila.
 *  2. Que una fila de **otro** médico no se toque ni deje rastro (la autorización ya no se resuelve por
 *     paciente, que sólo entiende fichas, sino por el registro de la propia fila).
 *  3. Que un cambio inválido (columna fuera de la lista, fila ajena, consulta que no se borra) se
 *     **descarte en silencio** y no trabe el resto del lote, que es la regla de la clase del sync.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncAppDataClinicaTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-clinica-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-' . substr(uniqid(), -6) . '@example.com',
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

    /**
     * Un paciente del médico con su historia y una consulta. El número de historia es único en el
     * esquema (y por médico en la práctica), así que se elige uno libre en vez de usar 1.
     */
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

    private function examen(Medico $medico, Consulta $consulta): ExamenFisico
    {
        return ExamenFisico::create([
            'reg_medico' => $medico->reg_medico,
            'historia' => $consulta->numhistoria,
            'consulta' => $consulta->nroconsulta,
        ]);
    }

    private function diagnostico(Medico $medico, Consulta $consulta, string $codigo = 'D01'): DiagnosticoPaciente
    {
        return DiagnosticoPaciente::create([
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'codediagnostico' => $codigo,
            'detalle_diagnostco' => 'CONTROL',
        ]);
    }

    private function antecedente(Medico $medico, Consulta $consulta, string $codigo = '0001'): AntecePaciente
    {
        return AntecePaciente::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $consulta->numhistoria,
            'codeantecedente' => $codigo,
            'descripcion' => 'ALERGIA',
        ]);
    }

    /** Manda un `changes` al sync, como lo haría el teléfono. */
    private function sincronizar(array $changes): void
    {
        $this->postJson('/api/app/sync-app-data', ['changes' => $changes])->assertOk();
    }

    /** El rastro de una fila, en orden de escritura. */
    private function rastros(string $tabla, int $registro)
    {
        return SyncChange::where('table_name', $tabla)
            ->where('record_id', $registro)
            ->orderBy('id')
            ->get();
    }

    public function test_editar_la_enfermedad_actual_desde_el_movil_deja_el_valor_y_el_rastro(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sincronizar([[
            'table' => 'consultas', 'operation' => 'updated', 'record_id' => $consulta->id,
            'column' => 'enfermedadactual', 'value' => 'DOLOR PELVICO DE TRES DIAS',
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame('DOLOR PELVICO DE TRES DIAS', $consulta->fresh()->enfermedadactual);

        $rastro = $this->rastros('consultas', $consulta->id);
        $this->assertCount(1, $rastro);
        $this->assertSame('mobile', $rastro[0]->source);
        $this->assertSame('updated', $rastro[0]->operation);
        $this->assertSame('enfermedadactual', $rastro[0]->column_name);
        $this->assertSame($medico->reg_medico, $rastro[0]->reg_medico, 'el rastro lleva el registro de la fila');
    }

    /**
     * Last-write-wins por **columna**: si el escritorio (o la web) ya registró un cambio más nuevo para
     * esa columna, el del teléfono se descarta aunque llegue después.
     */
    public function test_un_cambio_mas_nuevo_ya_registrado_le_gana_al_del_telefono(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $consulta->forceFill(['enfermedadactual' => 'LO DEL ESCRITORIO'])->save();

        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'consultas', 'record_id' => $consulta->id,
            'operation' => 'updated', 'column_name' => 'enfermedadactual', 'value' => 'LO DEL ESCRITORIO',
            'occurred_at' => now(), 'source' => 'escritorio',
        ]);

        $this->sincronizar([[
            'table' => 'consultas', 'operation' => 'updated', 'record_id' => $consulta->id,
            'column' => 'enfermedadactual', 'value' => 'LO DEL TELEFONO',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
        ]]);

        $this->assertSame('LO DEL ESCRITORIO', $consulta->fresh()->enfermedadactual);
        $this->assertSame(0, SyncChange::where('table_name', 'consultas')->where('record_id', $consulta->id)
            ->where('source', 'mobile')->count(), 'el cambio descartado no deja rastro');    }

    /** La autorización clínica es por el `reg_medico` de la fila: la de otro médico no se toca. */
    public function test_una_fila_de_otro_medico_queda_intacta_y_sin_rastro(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajena = $this->consulta($otro);
        $ajena->forceFill(['enfermedadactual' => 'DE LA OTRA DOCTORA'])->save();

        $this->medico();

        $this->sincronizar([[
            'table' => 'consultas', 'operation' => 'updated', 'record_id' => $ajena->id,
            'column' => 'enfermedadactual', 'value' => 'PISADA POR EL TELEFONO',
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame('DE LA OTRA DOCTORA', $ajena->fresh()->enfermedadactual);
        $this->assertCount(0, $this->rastros('consultas', $ajena->id), 'ni rastro: la fila no es de este médico');
    }

    /**
     * Una columna fuera de `WRITABLE_COLUMNS` se descarta **sin trabar el lote**: el resto de los cambios
     * se aplica igual. `consultas.resumen` existe en el esquema (la agrega pediatría) pero no está en la
     * lista blanca, así que si el gate fallara, el valor se escribiría.
     */
    public function test_una_columna_no_listada_se_descarta_y_el_resto_del_lote_se_aplica(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        $this->sincronizar([
            [
                'table' => 'consultas', 'operation' => 'updated', 'record_id' => $consulta->id,
                'column' => 'resumen', 'value' => 'NO DEBERIA ENTRAR',
                'occurred_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'consultas', 'operation' => 'updated', 'record_id' => $consulta->id,
                'column' => 'enfermedadactual', 'value' => 'ESTA SI ENTRA',
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $fresca = $consulta->fresh();
        $this->assertNull($fresca->resumen, 'la columna no listada no se escribe');
        $this->assertSame('ESTA SI ENTRA', $fresca->enfermedadactual, 'y el resto del lote se aplica');
        $this->assertSame(
            ['enfermedadactual'],
            $this->rastros('consultas', $consulta->id)->pluck('column_name')->all(),
            'sólo el cambio válido dejó rastro',
        );
    }

    public function test_editar_la_tension_del_examen_fisico_propio_aplica_y_deja_rastro(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $examen = $this->examen($medico, $consulta);

        $this->sincronizar([[
            'table' => 'examen_fisico_nuevo', 'operation' => 'updated', 'record_id' => $examen->id,
            'column' => 'tension', 'value' => '120/080',
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame('120/080', $examen->fresh()->tension);

        $rastro = $this->rastros('examen_fisico_nuevo', $examen->id);
        $this->assertCount(1, $rastro);
        $this->assertSame('mobile', $rastro[0]->source);
        $this->assertSame('tension', $rastro[0]->column_name);
        $this->assertSame($medico->reg_medico, $rastro[0]->reg_medico);
    }

    /**
     * Un diagnóstico **sí** se borra desde el teléfono (se agrega y se quita mientras se atiende), pero
     * sólo si la fila es del médico. La baja deja rastro `deleted` para que los demás equipos se enteren.
     */
    public function test_borrar_un_diagnostico_propio_funciona_y_el_ajeno_no(): void
    {
        $medico = $this->medico();
        $propio = $this->diagnostico($medico, $this->consulta($medico));

        $otro = $this->medico(autenticar: false);
        $ajeno = $this->diagnostico($otro, $this->consulta($otro));

        $this->sincronizar([
            ['table' => 'diagnostico_paciente', 'operation' => 'deleted', 'record_id' => $propio->id],
            ['table' => 'diagnostico_paciente', 'operation' => 'deleted', 'record_id' => $ajeno->id],
        ]);

        $this->assertDatabaseMissing('diagnostico_paciente', ['id' => $propio->id]);
        $this->assertDatabaseHas('diagnostico_paciente', ['id' => $ajeno->id]);

        $rastro = $this->rastros('diagnostico_paciente', $propio->id);
        $this->assertCount(1, $rastro);
        $this->assertSame('deleted', $rastro[0]->operation);
        $this->assertSame($medico->reg_medico, $rastro[0]->reg_medico);
        $this->assertCount(0, $this->rastros('diagnostico_paciente', $ajeno->id), 'la baja ajena no deja rastro');
    }

    public function test_borrar_un_antecedente_propio_funciona_y_el_ajeno_no(): void
    {
        $medico = $this->medico();
        $propio = $this->antecedente($medico, $this->consulta($medico));

        $otro = $this->medico(autenticar: false);
        $ajeno = $this->antecedente($otro, $this->consulta($otro));

        $this->sincronizar([
            ['table' => 'antece_paciente', 'operation' => 'deleted', 'record_id' => $propio->id],
            ['table' => 'antece_paciente', 'operation' => 'deleted', 'record_id' => $ajeno->id],
        ]);

        $this->assertDatabaseMissing('antece_paciente', ['id' => $propio->id]);
        $this->assertDatabaseHas('antece_paciente', ['id' => $ajeno->id]);
        $this->assertSame('deleted', $this->rastros('antece_paciente', $propio->id)->first()?->operation);
    }

    /** Una consulta y su examen físico **editan** pero no se borran: la historia clínica se borra en la web. */
    public function test_borrar_una_consulta_desde_el_movil_se_ignora(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $examen = $this->examen($medico, $consulta);

        $this->sincronizar([
            ['table' => 'consultas', 'operation' => 'deleted', 'record_id' => $consulta->id],
            ['table' => 'examen_fisico_nuevo', 'operation' => 'deleted', 'record_id' => $examen->id],
        ]);

        $this->assertDatabaseHas('consultas', ['id' => $consulta->id]);
        $this->assertDatabaseHas('examen_fisico_nuevo', ['id' => $examen->id]);
        $this->assertCount(0, $this->rastros('consultas', $consulta->id), 'ni rastro: la consulta no se borra');
        $this->assertCount(0, $this->rastros('examen_fisico_nuevo', $examen->id));

        // Y las listas que gobiernan el camino quedan dichas de forma explícita.
        $this->assertContains('consultas', SyncAppDataRequest::TABLAS_EDITABLES);
        $this->assertNotContains('consultas', SyncAppDataRequest::TABLAS_BORRABLES);
        $this->assertNotContains('examen_fisico_nuevo', SyncAppDataRequest::TABLAS_BORRABLES);
    }
}
