<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Credenciales de sincronización por equipo (reemplazo de la X-API-KEY hardcodeada en el `.pbl`).
 *
 * `DatabaseTransactions`, no `RefreshDatabase`: el proyecto no tiene `.env.testing` propio y correr
 * migraciones pisaría la base sqlite de desarrollo (ver MedicAPI/AGENTS.md).
 *
 * Lo que estos tests fijan, y que es lo importante del diseño:
 *  - la credencial vence DE VERDAD (aunque Sanctum en este proyecto no lo haga en estas rutas),
 *  - la revocación corta el acceso,
 *  - una credencial solo puede subir lotes de SU médico,
 *  - la X-API-KEY sigue funcionando (transición).
 */
class SyncCredencialTest extends TestCase
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

    private function emitirCredencial(string $regMedico, array $overrides = []): array
    {
        $token = SyncCredential::generarToken();

        $cred = SyncCredential::create(array_merge([
            'reg_medico'       => $regMedico,
            'machine_label'    => 'EQUIPO-TEST-' . uniqid(),
            'machine_host'     => 'PC-TEST',
            'bound_to_machine' => false,
            'token_hash'       => SyncCredential::hashToken($token),
            'expires_at'       => now()->addYear(),
        ], $overrides));

        return [$token, $cred];
    }

    /* ------------------------------------------------------------------ */

    public function test_la_clave_estatica_sigue_funcionando_durante_la_transicion(): void
    {
        $this->crearMedico('test-sync-001');

        $this->postJson('/api/sync/upload-batch', [
            'table'      => 'motivo_cita',
            'reg_medico' => 'test-sync-001',
            'data'       => [],
        ], ['X-API-KEY' => self::API_KEY])
            ->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    public function test_sin_credencial_devuelve_401(): void
    {
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []])
            ->assertStatus(401)
            ->assertJson(['status' => 'error', 'motivo' => 'falta']);
    }

    public function test_credencial_valida_permite_sincronizar(): void
    {
        [$token] = $this->emitirCredencial('test-sync-002');

        $this->postJson('/api/sync/upload-batch', [
            'table' => 'motivo_cita',
            'data'  => [],
        ], ['Authorization' => 'Bearer ' . $token, 'X-Equipo' => 'PC-TEST'])
            ->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    /**
     * Usar la credencial no puede mover su vencimiento.
     *
     * Es el invariante que rompió producción el 2026-10-07: `validarToken()` guarda `last_used_at` en cada
     * petición autenticada, y con `expires_at` declarado `TIMESTAMP NOT NULL` (la primera columna TIMESTAMP
     * de la tabla) MySQL/MariaDB le agregaba `ON UPDATE current_timestamp()`, así que el UPDATE del último
     * uso pisaba el vencimiento con "ahora" y la credencial vencía en su primer sync. Acá se fija el lado de
     * la aplicación (que guardar el uso no recalcule ni pise `expires_at`); la trampa del motor se corrige en
     * la migración `2026_10_07_120000_corrige_expires_at_de_sync_credentials` — SQLite (la base de tests) no
     * la tiene, por eso este test solo cubre la mitad que sí se puede reproducir acá.
     */
    public function test_usar_la_credencial_no_mueve_el_vencimiento(): void
    {
        [$token, $cred] = $this->emitirCredencial('test-sync-015');
        $vence = $cred->expires_at->toDateTimeString();

        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(200);

        $cred->refresh();

        $this->assertNotNull($cred->last_used_at, 'El uso tiene que quedar registrado.');
        $this->assertTrue($cred->estaActiva(), 'La credencial tiene que seguir activa después de usarse.');
        $this->assertSame($vence, $cred->expires_at->toDateTimeString(), 'El vencimiento no puede moverse al usar la credencial.');
    }

    public function test_la_credencial_vencida_es_rechazada(): void
    {
        [$token] = $this->emitirCredencial('test-sync-003', ['expires_at' => now()->subDay()]);

        $respuesta = $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(401)
            ->assertJson(['status' => 'error', 'motivo' => 'invalida'])
            ->json();

        $this->assertStringContainsString('venció', $respuesta['message']);
    }

    public function test_la_credencial_revocada_es_rechazada(): void
    {
        [$token, $cred] = $this->emitirCredencial('test-sync-004');
        $cred->forceFill(['revoked_at' => now()])->save();

        $respuesta = $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(401)
            ->assertJson(['status' => 'error', 'motivo' => 'invalida'])
            ->json();

        $this->assertStringContainsString('revocada', $respuesta['message']);
    }

    public function test_un_token_desconocido_es_rechazado(): void
    {
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ddr_sync_' . str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJson(['status' => 'error', 'motivo' => 'invalida']);
    }

    /**
     * Lo más importante del diseño: la credencial manda el médico. Si el payload intenta usar otro
     * reg_medico, se ignora el payload.
     */
    public function test_la_credencial_no_puede_escribir_datos_de_otro_medico(): void
    {
        $this->crearMedico('test-sync-mio');
        $this->crearMedico('test-sync-ajeno');

        [$token, $cred] = $this->emitirCredencial('test-sync-mio');

        // El payload miente sobre el reg_medico.
        $this->postJson('/api/pacientes/sincronizar', [
            'reg_medico' => 'test-sync-ajeno',
            'pacientes'  => [],
        ], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(200);

        // El servicio resolvió el médico desde la credencial.
        $this->assertSame('test-sync-mio', $cred->reg_medico);
    }

    public function test_los_endpoints_que_estaban_sin_auth_ahora_la_exigen(): void
    {
        foreach (['/api/pacientes/sincronizar', '/api/consultas/sincronizar', '/api/cola/sincronizar'] as $ruta) {
            $this->postJson($ruta, ['reg_medico' => 'x'])
                ->assertStatus(401);
        }
    }

    public function test_atadura_al_equipo_bloquea_otro_hostname(): void
    {
        [$token] = $this->emitirCredencial('test-sync-005', [
            'bound_to_machine' => true,
            'machine_host'     => 'PC-CONSULTORIO-1',
        ]);

        // Hostname distinto -> rechazado.
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $token, 'X-Equipo' => 'PC-OTRA'])
            ->assertStatus(401)
            ->assertJson(['motivo' => 'equipo_distinto']);

        // Hostname correcto -> pasa.
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $token, 'X-Equipo' => 'PC-CONSULTORIO-1'])
            ->assertStatus(200);
    }

    public function test_sin_atadura_un_hostname_distinto_no_bloquea(): void
    {
        [$token] = $this->emitirCredencial('test-sync-006', ['bound_to_machine' => false]);

        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $token, 'X-Equipo' => 'PC-RENOMBRADA'])
            ->assertStatus(200);
    }

    public function test_el_ultimo_uso_se_registra(): void
    {
        [$token, $cred] = $this->emitirCredencial('test-sync-007');
        $this->assertNull($cred->last_used_at);

        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(200);

        $this->assertNotNull($cred->fresh()->last_used_at);
    }

    public function test_el_token_no_se_guarda_en_claro(): void
    {
        [$token, $cred] = $this->emitirCredencial('test-sync-008');

        $this->assertNotSame($token, $cred->token_hash);
        $this->assertSame(hash('sha256', $token), $cred->token_hash);

        // Y no aparece en ningún lado de la fila.
        $this->assertStringNotContainsString($token, json_encode($cred->getAttributes()));
    }

    public function test_comando_listar_muestra_las_credenciales(): void
    {
        $this->emitirCredencial('test-sync-009', ['machine_label' => 'EQUIPO-LISTAR']);

        // `expectsOutput` recibe el texto exacto; acá se verifica que el comando corre y sale 0,
        // y que la credencial aparece en la tabla (la vista se prueba a mano, no por consola).
        $this->artisan('sync:credencial', ['accion' => 'listar'])
            ->assertExitCode(0);

        $this->assertSame(1, SyncCredential::where('machine_label', 'EQUIPO-LISTAR')->count());
    }

    public function test_comando_revocar_por_equipo(): void
    {
        [, $cred] = $this->emitirCredencial('test-sync-010', ['machine_label' => 'EQUIPO-REVOCAR']);

        $this->artisan('sync:credencial', ['accion' => 'revocar', '--equipo' => 'EQUIPO-REVOCAR'])
            ->assertExitCode(0);

        $this->assertNotNull($cred->fresh()->revoked_at);
    }

    public function test_comando_emitir_genera_una_credencial_usable(): void
    {
        $this->crearMedico('test-sync-011');

        $this->artisan('sync:credencial', [
            'accion'        => 'emitir',
            '--reg-medico'  => 'test-sync-011',
            '--equipo'      => 'EQUIPO-EMITIR',
            '--host'        => 'PC-EMITIR',
        ])->assertExitCode(0);

        $cred = SyncCredential::where('machine_label', 'EQUIPO-EMITIR')->first();
        $this->assertNotNull($cred);
        $this->assertSame('test-sync-011', $cred->reg_medico);
        $this->assertTrue($cred->estaActiva());
        // Vigencia por defecto: 1 año.
        $this->assertGreaterThan(360, $cred->diasParaVencer());
    }

    public function test_comando_emitir_sin_reg_medico_falla(): void
    {
        $this->artisan('sync:credencial', ['accion' => 'emitir', '--equipo' => 'X'])
            ->assertExitCode(1);
    }

    public function test_comando_emitir_con_forzar_revoca_las_previas(): void
    {
        $this->crearMedico('test-sync-012');
        [$tokenViejo] = $this->emitirCredencial('test-sync-012', ['machine_label' => 'EQUIPO-FORZAR']);

        $this->artisan('sync:credencial', [
            'accion'       => 'emitir',
            '--reg-medico' => 'test-sync-012',
            '--equipo'     => 'EQUIPO-FORZAR',
            '--forzar'     => true,
        ])->assertExitCode(0);

        // La credencial vieja quedó revocada...
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['Authorization' => 'Bearer ' . $tokenViejo])
            ->assertStatus(401);

        // ...y solo hay una activa para ese equipo.
        $this->assertSame(1, SyncCredential::where('machine_label', 'EQUIPO-FORZAR')
            ->whereNull('revoked_at')->count());
    }

    public function test_atar_equipo_sin_host_falla(): void
    {
        $this->crearMedico('test-sync-013');

        $this->artisan('sync:credencial', [
            'accion'        => 'emitir',
            '--reg-medico'  => 'test-sync-013',
            '--equipo'      => 'EQUIPO-SINHOST',
            '--atar-equipo' => true,
        ])->assertExitCode(1);
    }

    /* ------------------------------------------------------------------ */
    /* Rotation de la clave estatica (Paso A del plan de seguridad)        */
    /* ------------------------------------------------------------------ */

    /**
     * Una clave VACIA nunca debe autorizar. Es el caso que aparece cuando se vacia la asignacion
     * en el `.pbl` (`ls_api_key = ""`) y el cliente todavia no usa el puente: el header viaja con
     * el valor vacio y NO debe alcanzar para escribir.
     */
    public function test_una_clave_vacia_no_autoriza(): void
    {
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'data' => []],
            ['X-API-KEY' => ''])
            ->assertStatus(401);
    }

    /**
     * Si se rota la clave en el servidor, la vieja tiene que dejar de servir.
     * Se simula el cambio de config, que es lo que hace `sync:clave-estatica --rotar`.
     */
    public function test_al_rotar_la_clave_la_vieja_deja_de_servir(): void
    {
        $this->crearMedico('test-sync-014');

        config(['app.sync_api_key' => 'clave-nueva-rotada-xyz']);

        // La vieja (el default hardcodeado) ya no pasa...
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'reg_medico' => 'test-sync-014', 'data' => []],
            ['X-API-KEY' => 'MiClaveSecreta123!'])
            ->assertStatus(401);

        // ...y la nueva sí.
        $this->postJson('/api/sync/upload-batch', ['table' => 'motivo_cita', 'reg_medico' => 'test-sync-014', 'data' => []],
            ['X-API-KEY' => 'clave-nueva-rotada-xyz'])
            ->assertStatus(200);
    }

    public function test_el_comando_muestra_la_clave_en_uso(): void
    {
        $this->artisan('sync:clave-estatica')
            ->assertExitCode(0);
    }

    public function test_el_comando_avisa_cuando_es_el_default(): void
    {
        config(['app.sync_api_key' => 'MiClaveSecreta123!']);

        // `expectsOutputToContain` no existe en Laravel 8; se verifica el efecto, no el texto.
        $this->artisan('sync:clave-estatica')->assertExitCode(0);
        $this->assertSame('MiClaveSecreta123!', config('app.sync_api_key'));
    }

    /**
     * `--si` tiene que evitar el prompt incluso en modo interactivo (si no, una instalación
     * desatendida se queda esperando input para siempre).
     *
     * NOTA: el caso "sin TTY y sin --si" NO se puede probar acá — el harness de Laravel reporta
     * siempre `isInteractive() === true`, así que un test daría un falso resultado. Se verificó
     * contra el proceso real con stdin cerrado (ver el informe, § 9bis.5): Symfony igual hace el
     * prompt, pero al leer EOF falla en vez de colgarse. Para instalación desatendida va `--si`
     * (o `--no-interaction`).
     */
    public function test_emitir_con_si_permite_medico_inexistente_sin_prompt(): void
    {
        $this->artisan('sync:credencial', [
            'accion'       => 'emitir',
            '--reg-medico' => 'no-existe-si-' . uniqid(),
            '--equipo'     => 'EQUIPO-SI',
            '--si'         => true,
        ])->assertExitCode(0);  // si hubiera pedido confirmación, Mockery tiraría excepción

        $this->assertSame(1, SyncCredential::where('machine_label', 'EQUIPO-SI')->count());
    }
}
