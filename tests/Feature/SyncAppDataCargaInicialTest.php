<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncCarga;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **El app no sincroniza mientras el escritorio está subiendo la carga inicial.**
 *
 * Medido el 2026-10-22: mientras el escritorio vuelca la base entera, el API está insertando pacientes,
 * historias, consultas y catálogos de ese médico; si en ese rato el teléfono sube sus cambios, los dos
 * escriben sobre las mismas tablas y el resultado es una mezcla que nadie pidió.
 *
 * La regla que fijan estos tests: mientras haya una carga **en curso y viva**, `POST /app/sync-app-data`
 * responde `409 sincronizacion_en_curso` **sin aplicar nada** y sin mover el cursor; el teléfono conserva
 * su cola y reintenta solo. Una carga **abandonada** (sin lotes hace horas) no bloquea nada, y la carga de
 * **otro** consultorio tampoco.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncAppDataCargaInicialTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(string $sufijo = null): Medico
    {
        $regMedico = 'test-carga-' . ($sufijo ?? substr(uniqid(), -6));

        $user = User::create([
            'name'     => 'Doctora en carga',
            'email'    => 'carga-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Doctora',
            'lastname'   => 'En Carga',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);
        Sanctum::actingAs($user, ['*'], 'api');

        return $medico;
    }

    /** Una cita del médico, que el teléfono va a intentar editar. */
    private function cita(Medico $medico): Cola
    {
        return Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha'      => now()->toDateString(),
            'hora_ini'   => '08:00:00',
            'atendido'   => 0,
        ]);
    }

    /** La carga inicial del escritorio, como la deja `carga-inicial/iniciar`. */
    private function carga(Medico $medico, string $estado = SyncCarga::EN_CURSO): SyncCarga
    {
        return SyncCarga::create([
            'reg_medico'  => $medico->reg_medico,
            'medico_id'   => $medico->id,
            'estado'      => $estado,
            'iniciada_at' => now(),
        ]);
    }

    /** El avance de una tabla: es el signo de vida de la carga (ver `CargaInicialEnCurso`). */
    private function avance(SyncCarga $carga, \DateTimeInterface $cuando): void
    {
        DB::table('sync_carga_tablas')->insert([
            'sync_carga_id'   => $carga->id,
            'tabla'           => 'pacientes',
            'filas_esperadas' => 500,
            'filas_recibidas' => 250,
            'filas_omitidas'  => 0,
            'created_at'      => $cuando,
            'updated_at'      => $cuando,
        ]);
    }

    /** Un cambio del teléfono, como lo arma `SyncService` (editar una cita). */
    private function cambios(Cola $cita): array
    {
        return [[
            'table'       => 'cola',
            'operation'   => 'updated',
            'record_id'   => $cita->id,
            'column'      => 'motivo',
            'value'       => 'CONTROL',
            'occurred_at' => now()->toIso8601String(),
        ]];
    }

    private function sincronizar(array $cuerpo)
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo);
    }

    /* ------------------------------------------------------------------ */

    public function test_el_app_no_sincroniza_mientras_el_escritorio_sube_la_carga_inicial(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);
        $carga = $this->carga($medico);
        $this->avance($carga, now());

        $r = $this->sincronizar(['changes' => $this->cambios($cita)]);

        $r->assertStatus(409)
            ->assertHeader('Retry-After', '120')
            ->assertJson([
                'ok'    => false,
                'error' => 'sincronizacion_en_curso',
                'code'  => 'sincronizacion_en_curso',
            ]);

        // Sin `synced_at`: el app no puede creer que sincronizó (movería su cursor y no volvería a pedir
        // lo que se perdió).
        $this->assertArrayNotHasKey('synced_at', $r->json());
        $this->assertSame(120, $r->json('reintentar_en'));
        $this->assertNotNull($r->json('carga_iniciada_at'));

        // Y **nada se aplicó**: ni la cita cambió ni quedó rastro del cambio del teléfono.
        $this->assertNull($cita->fresh()->motivo);
        $this->assertSame(0, DB::table('sync_changes')
            ->where('table_name', 'cola')->where('record_id', $cita->id)->count());
    }

    public function test_cuando_la_carga_termina_el_app_vuelve_a_sincronizar(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);
        $carga = $this->carga($medico, SyncCarga::COMPLETA);
        $this->avance($carga, now());

        $this->sincronizar(['changes' => $this->cambios($cita)])
            ->assertOk()
            ->assertJsonStructure(['synced_at', 'colas']);

        $this->assertSame('CONTROL', $cita->fresh()->motivo);
    }

    /**
     * Una carga que se cortó (la PC se apagó, se cortó internet) queda `en_curso` para siempre. Bloquear el
     * app hasta que alguien la retome dejaría al médico sin sincronizar sin salida, así que sin lotes
     * recientes se considera abandonada y no bloquea.
     */
    public function test_una_carga_abandonada_no_bloquea_al_app(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);

        $carga = $this->carga($medico);
        $this->avance($carga, now()->subHours(\App\Sync\CargaInicialEnCurso::HORAS_DE_ABANDONO + 1));

        $this->sincronizar(['changes' => $this->cambios($cita)])->assertOk();

        $this->assertSame('CONTROL', $cita->fresh()->motivo);
    }

    /** La carga de otro consultorio no tiene nada que ver con este médico. */
    public function test_la_carga_de_otro_consultorio_no_bloquea(): void
    {
        $medico = $this->medico();
        $cita = $this->cita($medico);

        $ajeno = $this->medico('ajeno-' . substr(uniqid(), -6));
        $this->avance($this->carga($ajeno), now());

        // `medico()` cambia el usuario autenticado: se vuelve al primero.
        Sanctum::actingAs($medico->user, ['*'], 'api');

        $this->sincronizar(['changes' => $this->cambios($cita)])->assertOk();

        $this->assertSame('CONTROL', $cita->fresh()->motivo);
    }
}
