<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reagendar desde el app (ROADMAP.md Paso 19.C): es un `updated` sobre `cola.fecha` de la misma
 * cita, no cancelar y crear otra.
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: mismo motivo que `ConfiguracionMedicoTest`.
 */
class ReagendarCitaTest extends TestCase
{
    use DatabaseTransactions;

    private function citaDeMedicoAutenticado(): Cola
    {
        $regMedico = 'test-ra-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-ra-' . uniqid() . '@example.com',
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

        Sanctum::actingAs($user, ['*'], 'api');

        return Cola::create([
            'reg_medico' => $regMedico,
            'fecha' => '2026-09-25',
            'hora_ini' => '09:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 1,
        ]);
    }

    private function cambiarFecha(Cola $cola, $valor): void
    {
        $this->postJson('/api/app/sync-app-data', [
            'changes' => [[
                'table' => 'cola',
                'operation' => 'updated',
                'record_id' => $cola->id,
                'column' => 'fecha',
                'value' => $valor,
                'occurred_at' => now()->toIso8601String(),
            ]],
        ])->assertOk();
    }

    public function test_el_app_puede_mover_una_cita_a_otra_fecha(): void
    {
        $cola = $this->citaDeMedicoAutenticado();

        $this->cambiarFecha($cola, '2026-10-02');

        $this->assertSame('2026-10-02', $cola->fresh()->fecha->format('Y-m-d'));
    }

    public function test_una_fecha_mal_formada_o_nula_se_descarta_sin_trabar_el_sync(): void
    {
        $cola = $this->citaDeMedicoAutenticado();

        $this->cambiarFecha($cola, '02/10/2026');
        $this->cambiarFecha($cola, '2026-02-31');
        $this->cambiarFecha($cola, null);

        $this->assertSame('2026-09-25', $cola->fresh()->fecha->format('Y-m-d'));
    }
}
