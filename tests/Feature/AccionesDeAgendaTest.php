<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Las **Actions compartidas** de agenda vistas desde el sync (PLAN-WEB.md, R3 / WEB-1.4).
 *
 * El criterio de WEB-1.4 es "el sync sigue en verde y la web produce el mismo resultado". Estos
 * tests fijan la otra mitad: que el móvil, al escribir por `/app/sync-app-data`, termine en el mismo
 * estado que produce la web — porque el sync dejó de tener su propia copia de la regla y ahora llama
 * a `ConfirmarCita`, `AtenderCita`, `CobrarCita` y `ReordenarCola`.
 *
 * Desde **WEB-4.8** también fijan la regla que `AtenderCita` exige ("cita atendida ⇒ tiene historia",
 * TODO.md §9): el teléfono que manda `atendido = 1` sin historia no la marca, y el resto del lote se
 * aplica igual.
 */
class AccionesDeAgendaTest extends TestCase
{
    use DatabaseTransactions;

    /** Un número de historia fuera del rango que siembra `FakeClinicalDataSeeder` (1001–1050). */
    private const NUMHISTORIA = 4001;

    private Medico $medico;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name'     => 'Doctora de prueba',
            'email'    => 'agenda-sync-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);

        $this->medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Doctora',
            'lastname'   => 'De Prueba',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => 'agenda-sync-' . uniqid(),
        ]);

        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico]);

        Sanctum::actingAs($user, ['*'], 'api');
    }

    private function cita(array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $this->medico->reg_medico,
            'fecha'      => '2026-10-05',
            'hora_ini'   => '08:00:00',
            'numorden'   => 1,
            'atendido'   => 0,
            'estado'     => 0,
        ], $extra));
    }

    /**
     * Una cita **con historia**, para los casos que marcan `atendido`.
     *
     * Desde WEB-4.8 el servidor exige "cita atendida ⇒ tiene historia" (`AtenderCita`), así que una cita
     * sin `numhistoria` ya no se puede atender: el caso del teléfono tiene que traer el vínculo real. El
     * número se elige fuera del rango que siembra `FakeClinicalDataSeeder` (1001–1050), para no leer una
     * fila sembrada (trampa 27 del repo).
     */
    private function citaConHistoria(array $extra = []): Cola
    {
        return $this->cita(array_merge(['numhistoria' => self::NUMHISTORIA], $extra));
    }

    /** Una cita anclada a un paciente **sin** número todavía, como la deja el alta de la web (WEB-2.11). */
    private function citaSinHistoria(int $pacienteId): Cola
    {
        return $this->cita(['paciente_sinhistoria_id' => $pacienteId]);
    }

    /**
     * El paciente del médico, con su vínculo en el pivote y —si se pide— su fila en `historias`.
     *
     * `historiaNumerica` limpia el número del pivote, así que se guarda como texto, igual que el legado.
     */
    private function paciente(?int $numhistoria = null): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => 'Paciente',
            'apellidos'   => 'Del Sync',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $this->medico->id,
            'paciente_id' => $id,
            'numhistoria' => $numhistoria === null ? null : (string) $numhistoria,
            'reg_medico'  => $this->medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if ($numhistoria !== null) {
            Historia::create([
                'numhistoria' => (string) $numhistoria,
                'reg_medico'  => $this->medico->reg_medico,
                'paciente_id' => $id,
                'medico_id'   => $this->medico->id,
            ]);
        }

        return $id;
    }

    /** Manda un `changes` al sync, como lo haría el teléfono. */
    private function sincronizar(array $changes): void
    {
        $this->postJson('/api/app/sync-app-data', ['changes' => $changes])->assertOk();
    }

    public function test_el_sync_confirma_una_cita_como_la_web(): void
    {
        $cita = $this->cita();

        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'updated', 'record_id' => $cita->id,
            'column' => 'estado', 'value' => Cola::ESTADO_CONFIRMADA,
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $cita->fresh()->estado);
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)->where('column_name', 'estado')
            ->where('source', 'mobile')->count());
    }

    public function test_el_sync_atender_implica_confirmar(): void
    {
        $this->paciente(self::NUMHISTORIA);
        $cita = $this->citaConHistoria(['estado' => Cola::ESTADO_NO_CONFIRMADA]);

        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'updated', 'record_id' => $cita->id,
            'column' => 'atendido', 'value' => 1,
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $fresca->estado);
    }

    /**
     * **La regla de WEB-4.8 desde el teléfono**: marcar atendida una cita sin historia se **descarta** —la
     * cita se queda sin atender y el lote se aplica igual—, no rompe la sincronización.
     */
    public function test_el_sync_descarta_atender_una_cita_sin_historia_y_no_rompe_el_lote(): void
    {
        $sinHistoria = $this->citaSinHistoria($this->paciente());
        $conHistoria = $this->citaConHistoria(['hora_ini' => '09:00:00', 'numorden' => 2]);

        $this->sincronizar([
            [
                'table' => 'cola', 'operation' => 'updated', 'record_id' => $sinHistoria->id,
                'column' => 'atendido', 'value' => 1, 'occurred_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'cola', 'operation' => 'updated', 'record_id' => $conHistoria->id,
                'column' => 'atendido', 'value' => 1, 'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        // El que viola la regla no se aplica…
        $this->assertSame(0, (int) $sinHistoria->fresh()->atendido);
        $this->assertSame(0, SyncChange::where('table_name', 'cola')
            ->where('record_id', $sinHistoria->id)->where('column_name', 'atendido')->count());

        // …y el resto del lote sí: un cambio descartado no frena a los demás.
        $this->assertSame(1, (int) $conHistoria->fresh()->atendido);
    }

    /**
     * Y el otro matiz de la regla: si el vínculo del paciente **ya tiene** número, la cita se numera sola
     * en vez de rechazarse (el caso que TODO.md §9 describe: la cita nació con `paciente_temp_id` aunque
     * el paciente ya tuviera historia).
     */
    public function test_el_sync_numera_la_cita_que_tiene_la_historia_en_el_vinculo(): void
    {
        $pacienteId = $this->paciente(self::NUMHISTORIA);
        $cita = $this->citaSinHistoria($pacienteId);

        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'updated', 'record_id' => $cita->id,
            'column' => 'atendido', 'value' => 1, 'occurred_at' => now()->toIso8601String(),
        ]]);

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(self::NUMHISTORIA, (int) $fresca->numhistoria);
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)->where('column_name', 'numhistoria')
            ->where('source', 'mobile')->count());
    }

    public function test_el_sync_cobra_con_el_valor_absoluto_que_manda_el_telefono(): void
    {
        $cita = $this->cita(['monto' => 50, 'monto_pagado' => 20]);

        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'updated', 'record_id' => $cita->id,
            'column' => 'monto_pagado', 'value' => 50,
            'occurred_at' => now()->toIso8601String(),
        ]]);

        // 50, no 70: el teléfono ya sumó el abono en su copia; sumarlo otra vez lo duplicaría.
        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->fresh()->estadoPago());
    }

    public function test_el_sync_reordena_con_la_misma_regla_que_la_web(): void
    {
        $primera = $this->cita(['numorden' => 1, 'hora_ini' => '08:00:00']);
        $segunda = $this->cita(['numorden' => 2, 'hora_ini' => '08:30:00']);
        $tercera = $this->cita(['numorden' => 3, 'hora_ini' => '09:00:00']);

        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'reorder', 'record_id' => $tercera->id,
            'from' => 3, 'to' => 1, 'scope_date' => '2026-10-05',
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame(1, (int) $tercera->fresh()->numorden);
        $this->assertSame(2, (int) $primera->fresh()->numorden);
        $this->assertSame(3, (int) $segunda->fresh()->numorden);
        $this->assertSame(1, SyncChange::where('operation', 'reorder')->where('source', 'mobile')->count());
    }

    public function test_el_sync_respeta_el_ultimo_cambio_por_columna(): void
    {
        $cita = $this->cita();

        $nuevo = now();
        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'updated', 'record_id' => $cita->id,
            'column' => 'estado', 'value' => Cola::ESTADO_CONFIRMADA,
            'occurred_at' => $nuevo->toIso8601String(),
        ]]);

        // Una edición **más vieja** que llega después no pisa la nueva (last-write-wins por columna).
        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'updated', 'record_id' => $cita->id,
            'column' => 'estado', 'value' => Cola::ESTADO_NO_CONFIRMADA,
            'occurred_at' => $nuevo->copy()->subHour()->toIso8601String(),
        ]]);

        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $cita->fresh()->estado);
    }
}
