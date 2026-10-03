<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cuentas de acceso: alta con clave temporal, cambio obligatorio, reseteo, bloqueo y rol Administrador.
 *
 * `DatabaseTransactions`, no `RefreshDatabase` (ver MedicAPI/AGENTS.md): la base sqlite de desarrollo
 * trae datos reales y sin `.env.testing` las migraciones la pisarían. Por eso el test del "último
 * administrador" desactiva dentro de su transacción a los administradores que ya existen en la base.
 *
 * Lo que fijan, y que es lo importante del diseño:
 *  - una clave temporal NO da acceso a nada salvo cambiarla (aunque el token sea válido),
 *  - después de un reseteo hay UNA sola clave válida (antes `users` y `medicos` podían diferir),
 *  - bloquear corta el acceso y las sesiones, pero no puede dejar al sistema sin administradores,
 *  - la ficha del médico se resuelve por `medicos.user_id`, nunca por coincidencia de correo.
 */
class CuentasTest extends TestCase
{
    use DatabaseTransactions;

    private CuentaService $cuentas;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->cuentas = app(CuentaService::class);
    }

    private function nuevoMedico(string $clave = 'Temporal123'): array
    {
        $sufijo = uniqid();

        return $this->cuentas->crearMedico([
            'name'       => 'Doctora',
            'lastname'   => 'Prueba',
            'email'      => "doctora-{$sufijo}@example.com",
            'reg_medico' => "test-cuenta-{$sufijo}",
        ], $clave);
    }

    /** Petición con un token concreto: el guard cachea al usuario entre peticiones del mismo test. */
    private function conToken(string $metodo, string $url, string $token, array $datos = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->json($metodo, $url, $datos);
    }

    private function loginApi(string $email, string $clave)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/app/login', ['email' => $email, 'password' => $clave]);
    }

    /* ------------------------------------------------------------------ */
    /* Alta                                                                */
    /* ------------------------------------------------------------------ */

    public function test_crear_medico_deja_cuenta_ficha_rol_y_clave_temporal(): void
    {
        $r = $this->nuevoMedico('Temporal123');

        $this->assertSame('medico', $r['user']->tipo);
        $this->assertTrue($r['user']->must_change_password);
        $this->assertTrue($r['user']->is_active);
        $this->assertTrue($r['user']->hasRole('Medico'));
        $this->assertSame($r['user']->id, $r['medico']->user_id);
        $this->assertSame($r['medico']->id, $r['user']->medico->id);
        $this->assertTrue(Hash::check('Temporal123', $r['user']->password));
        // El legado se espeja: no pueden quedar dos claves distintas.
        $this->assertSame($r['user']->password, $r['medico']->password);
        $this->assertNotNull(MedicoRegistro::where('reg_medico', $r['medico']->reg_medico)->first());
        $this->assertFalse($r['user']->esAdministrador());
    }

    public function test_crear_administrador_y_clave_generada_si_no_se_da_una(): void
    {
        $r = $this->cuentas->crearAdministrador('Admin Prueba', 'admin-' . uniqid() . '@example.com');

        $this->assertSame('administrador', $r['user']->tipo);
        $this->assertTrue($r['user']->must_change_password);
        $this->assertTrue($r['user']->esAdministrador());
        $this->assertGreaterThanOrEqual(CuentaService::LARGO_MINIMO_CLAVE, strlen($r['clave']));
        $this->assertTrue(Hash::check($r['clave'], $r['user']->password));
    }

    public function test_no_se_repite_correo_ni_reg_medico(): void
    {
        $r = $this->nuevoMedico();

        try {
            $this->cuentas->crearAdministrador('Otro', $r['user']->email);
            $this->fail('Debió rechazar el correo repetido.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        try {
            $this->cuentas->crearMedico([
                'name' => 'X', 'lastname' => 'Y', 'email' => 'otro-' . uniqid() . '@example.com',
                'reg_medico' => $r['medico']->reg_medico,
            ]);
            $this->fail('Debió rechazar el reg_medico repetido.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reg_medico', $e->errors());
        }
    }

    public function test_una_clave_demasiado_corta_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        $this->cuentas->crearAdministrador('Admin', 'corta-' . uniqid() . '@example.com', 'abc');
    }

    public function test_dar_acceso_a_un_medico_que_no_tenia_cuenta(): void
    {
        $sufijo = uniqid();
        $medico = Medico::create([
            'name' => 'Sin', 'lastname' => 'Acceso', 'email' => "sin-acceso-{$sufijo}@example.com",
            'reg_medico' => "test-sin-acceso-{$sufijo}",
        ]);

        $r = $this->cuentas->darAccesoAMedico($medico, 'Temporal123');

        $this->assertSame($r['user']->id, $medico->fresh()->user_id);
        $this->assertTrue($r['user']->must_change_password);
        $this->assertTrue($r['user']->hasRole('Medico'));

        // Ya tiene cuenta: no se crea otra.
        $this->expectException(ValidationException::class);
        $this->cuentas->darAccesoAMedico($medico->fresh());
    }

    /* ------------------------------------------------------------------ */
    /* Primer inicio de sesión (API)                                       */
    /* ------------------------------------------------------------------ */

    public function test_con_clave_temporal_el_token_solo_sirve_para_cambiar_la_clave(): void
    {
        $r = $this->nuevoMedico('Temporal123');

        $login = $this->loginApi($r['user']->email, 'Temporal123')->assertOk();
        $login->assertJsonPath('must_change_password', true);
        $login->assertJsonPath('user.must_change_password', true);
        $token = $login->json('access_token');

        // Todo lo demás está cerrado, con un código que el app reconoce.
        $this->conToken('POST', '/api/app/sync-app-data', $token, [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');

        // Excepto leer la propia cuenta (el app la usa al reabrir para saber si debe mostrar la pantalla).
        $this->conToken('GET', '/api/user', $token)
            ->assertOk()
            ->assertJsonPath('must_change_password', true);
    }

    public function test_cambiar_la_clave_abre_el_acceso(): void
    {
        $r = $this->nuevoMedico('Temporal123');
        $token = $this->loginApi($r['user']->email, 'Temporal123')->json('access_token');

        // Clave actual equivocada, confirmación distinta, igual a la actual y muy corta: todo se rechaza.
        $this->conToken('POST', '/api/app/cambiar-password', $token, [
            'password_actual' => 'Equivocada1', 'password_nueva' => 'NuevaClave99', 'password_nueva_confirmation' => 'NuevaClave99',
        ])->assertStatus(422)->assertJsonValidationErrors('password_actual');

        $this->conToken('POST', '/api/app/cambiar-password', $token, [
            'password_actual' => 'Temporal123', 'password_nueva' => 'NuevaClave99', 'password_nueva_confirmation' => 'Distinta99',
        ])->assertStatus(422)->assertJsonValidationErrors('password_nueva');

        $this->conToken('POST', '/api/app/cambiar-password', $token, [
            'password_actual' => 'Temporal123', 'password_nueva' => 'Temporal123', 'password_nueva_confirmation' => 'Temporal123',
        ])->assertStatus(422)->assertJsonValidationErrors('password_nueva');

        $this->conToken('POST', '/api/app/cambiar-password', $token, [
            'password_actual' => 'Temporal123', 'password_nueva' => 'corta', 'password_nueva_confirmation' => 'corta',
        ])->assertStatus(422)->assertJsonValidationErrors('password_nueva');

        $this->assertTrue($r['user']->fresh()->must_change_password, 'Con errores la clave sigue siendo temporal.');

        // Ahora sí.
        $this->conToken('POST', '/api/app/cambiar-password', $token, [
            'password_actual' => 'Temporal123', 'password_nueva' => 'NuevaClave99', 'password_nueva_confirmation' => 'NuevaClave99',
        ])->assertOk()->assertJsonPath('must_change_password', false);

        $this->assertFalse($r['user']->fresh()->must_change_password);

        // La sesión desde la que cambió la clave sigue viva y ya no recibe el 403 de clave temporal.
        $respuesta = $this->conToken('POST', '/api/app/sync-app-data', $token, []);
        $this->assertNotSame(403, $respuesta->status(), 'Tras cambiar la clave el token ya debe servir.');

        // Y la clave temporal ya no entra, la nueva sí.
        $this->loginApi($r['user']->email, 'Temporal123')->assertStatus(401);
        $this->loginApi($r['user']->email, 'NuevaClave99')->assertOk()->assertJsonPath('must_change_password', false);
    }

    /* ------------------------------------------------------------------ */
    /* Reseteo                                                             */
    /* ------------------------------------------------------------------ */

    public function test_resetear_deja_una_sola_clave_valida_y_cierra_sesiones(): void
    {
        $r = $this->nuevoMedico('Temporal123');
        $token = $this->loginApi($r['user']->email, 'Temporal123')->json('access_token');
        $this->conToken('POST', '/api/app/cambiar-password', $token, [
            'password_actual' => 'Temporal123', 'password_nueva' => 'ClaveDelMedico1', 'password_nueva_confirmation' => 'ClaveDelMedico1',
        ])->assertOk();
        $this->assertSame(1, $r['user']->tokens()->count());

        $nueva = $this->cuentas->resetearClave($r['user']->fresh());

        $user = $r['user']->fresh();
        $this->assertTrue($user->must_change_password, 'Tras un reseteo vuelve a ser temporal, igual que el primer inicio.');
        $this->assertSame(0, $user->tokens()->count(), 'El reseteo cierra todas las sesiones.');

        // Una sola clave válida, en las dos tablas.
        $this->assertTrue(Hash::check($nueva, $user->password));
        $this->assertTrue(Hash::check($nueva, $user->medico->password));
        $this->assertFalse(Hash::check('ClaveDelMedico1', $user->medico->password));

        $this->loginApi($user->email, 'ClaveDelMedico1')->assertStatus(401);
        $this->loginApi($user->email, 'Temporal123')->assertStatus(401);
        $this->loginApi($user->email, $nueva)->assertOk()->assertJsonPath('must_change_password', true);
    }

    public function test_si_hay_cuenta_la_clave_vieja_de_medicos_no_abre_la_puerta(): void
    {
        // El defecto de antes: login probaba `users` y, si fallaba, `medicos.password`.
        $r = $this->nuevoMedico('Temporal123');
        $r['medico']->forceFill(['password' => Hash::make('ClaveVieja123')])->save();

        $this->loginApi($r['user']->email, 'ClaveVieja123')->assertStatus(401);
        $this->loginApi($r['user']->email, 'Temporal123')->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Bloqueo                                                             */
    /* ------------------------------------------------------------------ */

    public function test_bloquear_corta_login_y_sesiones_y_desbloquear_lo_restituye(): void
    {
        $r = $this->nuevoMedico('Temporal123');
        $token = $this->loginApi($r['user']->email, 'Temporal123')->json('access_token');

        $this->cuentas->bloquear($r['user'], 'Falta de pago');

        $user = $r['user']->fresh();
        $this->assertFalse($user->is_active);
        $this->assertSame('Falta de pago', $user->blocked_reason);
        $this->assertFalse((bool) $r['medico']->fresh()->is_active, 'Se espeja en la ficha del médico.');
        $this->assertSame(0, $user->tokens()->count());

        $this->conToken('GET', '/api/user', $token)->assertStatus(401);

        $this->loginApi($user->email, 'Temporal123')
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_blocked');
        // Con la clave equivocada no se revela que la cuenta existe.
        $this->loginApi($user->email, 'Equivocada1')->assertStatus(401);

        $this->cuentas->desbloquear($user);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNull($user->fresh()->blocked_reason);
        $this->loginApi($user->email, 'Temporal123')->assertOk();
    }

    public function test_un_token_emitido_antes_del_bloqueo_tampoco_pasa_por_el_middleware(): void
    {
        // Defensa extra: aunque algo dejara vivo un token, EnsureAccountActive responde 403 en el API.
        $r = $this->nuevoMedico('Temporal123');
        $token = $this->loginApi($r['user']->email, 'Temporal123')->json('access_token');

        $r['user']->forceFill(['is_active' => false])->save();

        $this->conToken('GET', '/api/user', $token)
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_blocked');
    }

    public function test_no_se_puede_bloquear_al_ultimo_administrador_ni_a_uno_mismo(): void
    {
        // La base de desarrollo ya trae administradores: se apartan dentro de esta transacción.
        User::administradores()->update(['is_active' => false]);

        $a = $this->cuentas->crearAdministrador('Unico Admin', 'unico-' . uniqid() . '@example.com')['user'];

        try {
            $this->cuentas->bloquear($a);
            $this->fail('Debió negarse a bloquear al último administrador.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cuenta', $e->errors());
        }

        try {
            $this->cuentas->quitarAdministrador($a);
            $this->fail('Debió negarse a quitar el rol al último administrador.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cuenta', $e->errors());
        }

        // Con otro administrador activo ya se puede, salvo a uno mismo.
        $b = $this->cuentas->crearAdministrador('Segundo Admin', 'segundo-' . uniqid() . '@example.com')['user'];

        try {
            $this->cuentas->bloquear($b, '', $b);
            $this->fail('No debe poder bloquearse a sí mismo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('propia cuenta', $e->errors()['cuenta'][0]);
        }

        $this->cuentas->bloquear($a, 'prueba', $b);
        $this->assertFalse($a->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ */
    /* Médico ⇄ cuenta, y rol Administrador                                */
    /* ------------------------------------------------------------------ */

    public function test_un_medico_puede_ser_tambien_administrador_sin_cambiar_su_tipo(): void
    {
        $r = $this->nuevoMedico();
        $this->assertFalse($r['user']->esAdministrador());

        $this->cuentas->hacerAdministrador($r['user']);

        $user = $r['user']->fresh();
        $this->assertTrue($user->esAdministrador());
        $this->assertSame('medico', $user->tipo, 'Administrador es un rol, no un tipo: sigue entrando como médico.');
        $this->assertNotNull($user->medico);
    }

    public function test_la_ficha_se_resuelve_por_user_id_y_nunca_por_correo(): void
    {
        // Antes el app hacía `user_id = ? OR email = ?`: bastaba un correo igual para quedarse con la ficha.
        $sufijo = uniqid();
        $user = User::create(['name' => 'Intruso', 'email' => "intruso-{$sufijo}@example.com", 'password' => Hash::make('Temporal123')]);
        Medico::create([
            'name' => 'Ajeno', 'lastname' => 'Ajeno', 'email' => $user->email, 'reg_medico' => "test-ajeno-{$sufijo}",
        ]);

        $this->assertNull($user->medico);

        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'api')->postJson('/api/app/sync-app-data', [])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Esta cuenta no tiene un médico asociado.');
    }

    public function test_todo_medico_de_la_base_resuelve_a_su_ficha_por_user_id(): void
    {
        // Lo que dejó la migración de relleno: ningún médico enlazado quedó sin su cuenta.
        $medicos = Medico::whereNotNull('user_id')->get();
        $this->assertNotEmpty($medicos);

        foreach ($medicos as $medico) {
            $user = User::find($medico->user_id);
            $this->assertNotNull($user, "El médico {$medico->id} apunta a un usuario que no existe.");
            $this->assertSame($medico->id, $user->medico->id);
            $this->assertSame('medico', $user->tipo, "La cuenta {$user->email} debió quedar con tipo 'medico'.");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Comando de arranque                                                 */
    /* ------------------------------------------------------------------ */

    public function test_comando_cuentas_admin_da_el_rol_y_pide_cambiar_la_clave(): void
    {
        $r = $this->nuevoMedico();
        $r['user']->forceFill(['must_change_password' => false])->save();

        $this->artisan('cuentas:admin', ['email' => strtoupper($r['user']->email)])
            ->expectsOutput($r['user']->email . ' ahora es administrador (tipo de cuenta: medico).')
            ->assertExitCode(0);

        $user = $r['user']->fresh();
        $this->assertTrue($user->esAdministrador());
        $this->assertTrue($user->must_change_password);
        $this->assertSame('medico', $user->tipo);

        // Idempotente.
        $this->artisan('cuentas:admin', ['email' => $user->email])
            ->expectsOutput($user->email . ' ya era administrador.')
            ->assertExitCode(0);
    }

    public function test_comando_cuentas_admin_con_correo_inexistente_falla(): void
    {
        $email = 'nadie-' . uniqid() . '@example.com';

        $this->artisan('cuentas:admin', ['email' => $email])
            ->expectsOutput("No existe ninguna cuenta con el correo {$email}. Créala primero (por ejemplo desde el panel de médicos).")
            ->assertExitCode(1);
    }

    public function test_el_comando_puede_dejar_la_clave_como_esta(): void
    {
        $r = $this->nuevoMedico();
        $r['user']->forceFill(['must_change_password' => false])->save();

        $this->artisan('cuentas:admin', ['email' => $r['user']->email, '--sin-cambio-de-clave' => true])->assertExitCode(0);

        $this->assertFalse($r['user']->fresh()->must_change_password);
    }

    /* ------------------------------------------------------------------ */
    /* Web                                                                 */
    /* ------------------------------------------------------------------ */

    public function test_login_web_con_clave_temporal_manda_a_cambiarla_y_luego_entra(): void
    {
        $r = $this->nuevoMedico('Temporal123');

        // `user_type=Root` enviado a mano se ignora: manda la cuenta, no el selector.
        $this->post('/login', ['email' => $r['user']->email, 'password' => 'Temporal123', 'user_type' => 'Root'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($r['user']);

        $this->get('/dashboard')->assertRedirect('/cambiar-password');
        $this->get('/cambiar-password')->assertOk()->assertSee('Tu contraseña es temporal');

        $this->post('/cambiar-password', [
            'password_actual' => 'Temporal123', 'password_nueva' => 'NuevaClave99', 'password_nueva_confirmation' => 'NuevaClave99',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse($r['user']->fresh()->must_change_password);
        $this->get('/dashboard')->assertOk();
    }

    public function test_login_web_rechaza_clave_incorrecta_y_cuenta_bloqueada(): void
    {
        $r = $this->nuevoMedico('Temporal123');

        $this->from('/login')->post('/login', ['email' => $r['user']->email, 'password' => 'Equivocada1'])
            ->assertSessionHasErrors('password');
        $this->assertGuest();

        $r['user']->forceFill(['is_active' => false])->save();

        $this->from('/login')->post('/login', ['email' => $r['user']->email, 'password' => 'Temporal123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_el_formulario_de_login_ya_no_ofrece_acceso_root(): void
    {
        $this->get('/login')->assertOk()
            ->assertDontSee('systemAccessCheck')
            ->assertDontSee('Acceso de Administración');
    }
}
