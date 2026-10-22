<?php

namespace Tests\Feature;

use App\Models\SyncCarga;
use App\Services\CuentaService;
use App\Services\SyncCredencialService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **El camino completo de una instalación nueva**, tal como ocurre en la vida real y sin atajos:
 *
 *  1. el administrador da de alta al médico en el panel (`CuentaService::crearMedico`, lo mismo que
 *     «Usuarios → Nuevo médico»);
 *  2. le genera la API key del consultorio (`SyncCredencialService::emitir`, lo mismo que el botón
 *     «Generar API key» de `/admin/api-keys`);
 *  3. el escritorio sube su carga inicial con la credencial **de verdad** (Bearer, la que acaba de
 *     emitirse): 1 paciente y la consulta de ese paciente;
 *  4. el escritorio intenta subir la carga otra vez y el API lo rechaza, porque ese médico ya sincronizó.
 *
 * Es el flujo que se rompió el 2026-10-22 con una doctora real (los catálogos que copia el panel hacían
 * que la carga se rechazara como «médico con datos»), y el que fija que la carga inicial sea **una sola
 * vez por médico**: después de `finalizar`, el botón del escritorio no vuelve y nada se puede colar.
 *
 * `DatabaseTransactions` (ver MedicAPI/AGENTS.md): la base sqlite de desarrollo trae datos reales.
 */
class SincronizacionDesdeCeroTest extends TestCase
{
    use DatabaseTransactions;

    /** La credencial del equipo, emitida en el paso 2 y usada en los pasos 3 y 4. */
    private string $token = '';

    /* ------------------------------------------------------------------ */
    /* El flujo                                                            */
    /* ------------------------------------------------------------------ */

    public function test_la_instalacion_sube_su_carga_inicial_una_sola_vez(): void
    {
        $sufijo = substr(uniqid(), -6);
        $reg = 'cero-t-' . $sufijo;

        // --- 1) El médico en el panel ------------------------------------
        $alta = app(CuentaService::class)->crearMedico([
            'name'       => 'Doctora',
            'lastname'   => 'Desde Cero',
            'email'      => "cero-{$sufijo}@example.com",
            'reg_medico' => $reg,
        ]);

        $this->assertSame($reg, $alta['medico']->reg_medico, 'el alta deja el reg_medico que va a usar el escritorio');
        $this->assertNull(SyncCarga::where('reg_medico', $reg)->first(), 'todavía no sincronizó');

        // --- 2) La API key del consultorio -------------------------------
        $credencial = app(SyncCredencialService::class)->emitir($reg, 'CONSULTORIO-1');
        $this->token = $credencial['token'];

        $this->assertSame($reg, $credencial['credencial']->reg_medico);
        $this->assertStringStartsWith('ddr_sync_', $this->token);
        $this->assertSame(hash('sha256', $this->token), $credencial['credencial']->token_hash,
            'en la base queda el hash, nunca el token en claro');

        // --- 3) La carga inicial: 1 paciente y su consulta ----------------
        $carga = (int) $this->escritorio('carga-inicial/iniciar', [
            'tablas' => ['pacientes' => 1, 'consultas' => 1],
        ])->assertOk()->assertJson(['ok' => true, 'estado' => 'en_curso', 'retomada' => 0])->json('carga_id');

        $this->escritorio('carga-inicial/lote', [
            'carga_id' => $carga, 'tabla' => 'pacientes', 'desde' => 0,
            'filas' => [[
                'numhistoria' => 1, 'cedula' => 'V-1', 'nac' => 'V',
                'apellidos' => 'PRUEBA', 'nombres' => 'ANA', 'sexo' => 'F',
            ]],
        ])->assertOk()->assertJson(['recibidas' => 1, 'duplicado' => 0]);

        $this->escritorio('carga-inicial/lote', [
            'carga_id' => $carga, 'tabla' => 'consultas', 'desde' => 0,
            'filas' => [['numhistoria' => 1, 'nroconsulta' => 1, 'fecha' => '2026-10-20']],
        ])->assertOk()->assertJson(['recibidas' => 1]);

        $fin = $this->escritorio('carga-inicial/finalizar', ['carga_id' => $carga])
            ->assertOk()->assertJson(['ok' => true, 'estado' => SyncCarga::COMPLETA, 'tablas' => 2, 'filas' => 2]);

        $this->assertNotSame('', (string) $fin->json('estado'));

        // Lo que quedó en el API: el paciente, su historia, el vínculo con el consultorio y la consulta.
        foreach (['pacientes' => 1, 'historias' => 1, 'medico_pacientes' => 1, 'consultas' => 1] as $tabla => $esperadas) {
            $this->assertSame($esperadas, DB::table($tabla)->where('reg_medico', $reg)->count(), "{$tabla} del consultorio");
        }

        // El escritorio ya sabe que la carga terminó: con esto se oculta el botón «Iniciar Sincronización».
        $this->escritorio('cambios/estado', [])
            ->assertOk()
            ->assertJson(['carga' => SyncCarga::COMPLETA, 'reg_medico' => $reg]);

        // --- 4) Subir la carga otra vez: se rechaza ----------------------
        $rechazo = $this->escritorio('carga-inicial/iniciar', ['tablas' => ['pacientes' => 1, 'consultas' => 1]])
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'error' => 'carga_ya_completa']);

        $this->assertStringContainsString('ya se completó', (string) $rechazo->json('mensaje'),
            'el mensaje le dice al técnico cuándo se completó');

        // Y tampoco se puede colar un lote nuevo en la carga que ya quedó cerrada.
        $this->escritorio('carga-inicial/lote', [
            'carga_id' => $carga, 'tabla' => 'pacientes', 'desde' => 1,
            'filas' => [[
                'numhistoria' => 2, 'cedula' => 'V-2', 'nac' => 'V',
                'apellidos' => 'OTRO', 'nombres' => 'LUIS', 'sexo' => 'M',
            ]],
        ])->assertStatus(409)->assertJson(['ok' => false, 'error' => 'carga_cerrada']);

        // Nada se duplicó, y el segundo paciente no entró por ninguna puerta.
        $this->assertSame(1, DB::table('pacientes')->where('reg_medico', $reg)->count());
        $this->assertSame(1, DB::table('consultas')->where('reg_medico', $reg)->count());
        $this->assertSame(1, SyncCarga::where('reg_medico', $reg)->count(), 'sigue habiendo una sola carga');
    }

    /**
     * Un equipo que **no** tiene la credencial de este médico no puede tocar su carga, y uno revocado
     * tampoco: la carga inicial es del consultorio dueño de la API key, no del `reg_medico` del payload.
     */
    public function test_la_carga_inicial_es_del_consultorio_de_la_credencial(): void
    {
        $sufijo = substr(uniqid(), -6);
        $reg = 'cero-x-' . $sufijo;

        app(CuentaService::class)->crearMedico([
            'name' => 'Doctor', 'lastname' => 'Ajeno', 'email' => "cero-x-{$sufijo}@example.com", 'reg_medico' => $reg,
        ]);

        // Credencial de OTRO médico.
        $otro = 'cero-o-' . $sufijo;
        app(CuentaService::class)->crearMedico([
            'name' => 'Doctor', 'lastname' => 'Otro', 'email' => "cero-o-{$sufijo}@example.com", 'reg_medico' => $otro,
        ]);
        $this->token = app(SyncCredencialService::class)->emitir($otro, 'CONSULTORIO-2')['token'];

        // El cuerpo dice `$reg` pero manda la credencial de `$otro`: gana la credencial, y la carga se
        // crea para SU médico (nunca para el que escribe el payload).
        $carga = $this->escritorio('carga-inicial/iniciar', [
            'reg_medico' => $reg, 'tablas' => ['pacientes' => 1],
        ])->assertOk()->json('carga_id');

        $this->assertSame($otro, SyncCarga::find($carga)->reg_medico);
        $this->assertNull(SyncCarga::where('reg_medico', $reg)->first(), 'el médico del payload no quedó tocado');
    }

    /* ------------------------------------------------------------------ */
    /* Ayudas                                                              */
    /* ------------------------------------------------------------------ */

    /** Un POST del escritorio, con la credencial del equipo (lo que manda `sync.exe`). */
    private function escritorio(string $accion, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$accion}", $cuerpo, [
            'Authorization' => "Bearer {$this->token}",
            'X-Equipo'      => 'CONSULTORIO-1',
        ]);
    }
}
