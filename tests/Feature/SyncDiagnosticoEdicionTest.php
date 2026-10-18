<?php

namespace Tests\Feature;

use App\Http\Requests\SyncAppDataRequest;
use App\Models\Consulta;
use App\Models\DiagnosticoPaciente;
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
 * **Editar el detalle de un diagnóstico ya cargado, desde el teléfono.**
 *
 * El sync ya creaba (A4a) y borraba (A1) diagnósticos de la consulta, pero **corregir** lo que se había
 * escrito no: `WRITABLE_COLUMNS['diagnostico_paciente']` no existía y el controlador descartaba la
 * edición en silencio. Lo que se fija acá:
 *
 *  1. Que `detalle_diagnostco` —el texto propio del diagnóstico, la corrección del médico sobre la
 *     descripción del catálogo— y `orden` **entren** y dejen su rastro `source = mobile` con el
 *     `reg_medico` de la fila.
 *  2. Que `codediagnostico` **no** entre: cambiar de diagnóstico no es corregir la fila, es otra fila.
 *     Se descarta sin trabar el resto del lote, que es la regla de la clase del sync.
 *  3. Que la fila de **otro** médico no se toque ni deje rastro (la autorización es por el `reg_medico`
 *     de la propia fila).
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncDiagnosticoEdicionTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-diagnost' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-dx-ed' . substr(uniqid(), -6) . '@example.com',
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

    /**
     * Un diagnóstico ya cargado de la consulta, como lo dejaría el escritorio o la web: el detalle es la
     * copia de la descripción del catálogo y el orden, su lugar en la lista.
     */
    private function diagnostico(Medico $medico, Consulta $consulta, string $codigo = '0001'): DiagnosticoPaciente
    {
        return DiagnosticoPaciente::create([
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'codediagnostico' => $codigo,
            'detalle_diagnostco' => 'LIE BAJO GRADO',
            'orden' => 1,
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

    public function test_editar_el_detalle_de_un_diagnostico_propio_lo_deja_y_deja_rastro_mobile(): void
    {
        $medico = $this->medico();
        $diagnostico = $this->diagnostico($medico, $this->consulta($medico));

        $this->sincronizar([[
            'table' => 'diagnostico_paciente', 'operation' => 'updated', 'record_id' => $diagnostico->id,
            'column' => 'detalle_diagnostco', 'value' => 'LIE BAJO GRADO, CONTROL EN 6 MESES',
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame(
            'LIE BAJO GRADO, CONTROL EN 6 MESES',
            $diagnostico->fresh()->detalle_diagnostco,
            'el texto corregido en el teléfono queda en la fila',
        );

        $rastro = $this->rastros('diagnostico_paciente', $diagnostico->id);
        $this->assertCount(1, $rastro);
        $this->assertSame('mobile', $rastro[0]->source);
        $this->assertSame('updated', $rastro[0]->operation);
        $this->assertSame('detalle_diagnostco', $rastro[0]->column_name);
        $this->assertSame($medico->reg_medico, $rastro[0]->reg_medico, 'el rastro lleva el registro de la fila');

        // La tabla ya estaba abierta para editar desde A1; lo que faltaba era su lista de columnas.
        $this->assertContains('diagnostico_paciente', SyncAppDataRequest::TABLAS_EDITABLES);
    }

    public function test_editar_el_orden_de_un_diagnostico_propio_lo_deja_y_deja_rastro(): void
    {
        $medico = $this->medico();
        $diagnostico = $this->diagnostico($medico, $this->consulta($medico));

        $this->sincronizar([[
            'table' => 'diagnostico_paciente', 'operation' => 'updated', 'record_id' => $diagnostico->id,
            'column' => 'orden', 'value' => 3,
            'occurred_at' => now()->toIso8601String(),
        ]]);

        // El modelo castea `orden` a entero: lo que se comprueba es el número, no el texto del payload.
        $this->assertSame(3, $diagnostico->fresh()->orden);

        $rastro = $this->rastros('diagnostico_paciente', $diagnostico->id);
        $this->assertCount(1, $rastro);
        $this->assertSame('mobile', $rastro[0]->source);
        $this->assertSame('orden', $rastro[0]->column_name);
        $this->assertSame($medico->reg_medico, $rastro[0]->reg_medico);
    }

    /**
     * `codediagnostico` no está en la lista blanca: cambiar de diagnóstico es quitar una fila y crear otra.
     * El intento se descarta **sin trabar el lote**, así que la edición válida que viaja al lado se aplica.
     */
    public function test_editar_el_codigo_del_diagnostico_se_descarta_y_el_resto_del_lote_se_aplica(): void
    {
        $medico = $this->medico();
        $diagnostico = $this->diagnostico($medico, $this->consulta($medico));

        $this->sincronizar([
            [
                'table' => 'diagnostico_paciente', 'operation' => 'updated', 'record_id' => $diagnostico->id,
                'column' => 'codediagnostico', 'value' => '0002',
                'occurred_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'diagnostico_paciente', 'operation' => 'updated', 'record_id' => $diagnostico->id,
                'column' => 'detalle_diagnostco', 'value' => 'ESTE SI ENTRA',
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $fresca = $diagnostico->fresh();
        $this->assertSame('0001', $fresca->codediagnostico, 'el código no se edita: es el diagnóstico de la fila');
        $this->assertSame('ESTE SI ENTRA', $fresca->detalle_diagnostco, 'y el resto del lote se aplica');
        $this->assertSame(
            ['detalle_diagnostco'],
            $this->rastros('diagnostico_paciente', $diagnostico->id)->pluck('column_name')->all(),
            'sólo el cambio válido dejó rastro',
        );
    }

    public function test_editar_el_detalle_de_un_diagnostico_de_otro_medico_no_se_toca(): void
    {
        $otro = $this->medico(autenticar: false);
        $ajeno = $this->diagnostico($otro, $this->consulta($otro));

        $this->medico();

        $this->sincronizar([[
            'table' => 'diagnostico_paciente', 'operation' => 'updated', 'record_id' => $ajeno->id,
            'column' => 'detalle_diagnostco', 'value' => 'PISADO POR EL TELEFONO',
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame('LIE BAJO GRADO', $ajeno->fresh()->detalle_diagnostco);
        $this->assertCount(0, $this->rastros('diagnostico_paciente', $ajeno->id), 'ni rastro: la fila no es de este médico');
    }
}
