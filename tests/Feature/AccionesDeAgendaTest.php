<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
 */
class AccionesDeAgendaTest extends TestCase
{
    use DatabaseTransactions;

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
        $cita = $this->cita(['estado' => Cola::ESTADO_NO_CONFIRMADA]);

        $this->sincronizar([[
            'table' => 'cola', 'operation' => 'updated', 'record_id' => $cita->id,
            'column' => 'atendido', 'value' => 1,
            'occurred_at' => now()->toIso8601String(),
        ]]);

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $fresca->estado);
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
