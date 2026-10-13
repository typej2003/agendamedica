<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Usuarios del consultorio** (WEB-8.5): el médico gestiona a su secretaria desde `/clinica/usuarios`.
 *
 * Lo que estos casos fijan:
 *
 *  - **Quién entra**: el médico del contexto; la secretaría recibe 403 (también en cada acción por POST) y no ve la entrada del
 *    menú; el invitado va al login.
 *  - **El alta**: cuenta nueva con rol `Secretaria`, vinculada (`medico_registros.user_id`) al `reg_medico` del contexto y con una
 *    clave temporal que obliga a cambiarla; la clave se muestra **una sola vez**. Un correo que ya tiene cuenta se rechaza (nunca se
 *    "adopta" la de otra persona) y no hay forma de dar otro rol.
 *  - **Restablecer, bloquear, desbloquear y quitar el acceso**, sin borrar nada; una bloqueada no entra; con más de un
 *    consultorio el médico sólo le quita SU acceso.
 *  - **Aislamiento**: una cuenta de otro médico, de administración o que también es médico responde 404.
 */
class UsuariosDelConsultorioWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();

        parent::tearDown();
    }

    private function como(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    private function nueva(string $nombre = 'María Gómez', ?string $correo = null): array
    {
        return ['name' => $nombre, 'email' => $correo ?? 'maria-' . uniqid() . '@example.com'];
    }

    /** Crea la secretaria por la pantalla y devuelve su cuenta y la clave temporal que se mostró. */
    private function crearPorPantalla(User $user, Medico $medico, Specialty $gineco, array $datos = []): array
    {
        $datos = $datos + $this->nueva();

        $respuesta = $this->como($user, $medico, $gineco)->post(route('clinica.usuarios.crear'), $datos);
        $respuesta->assertRedirect(route('clinica.usuarios'));
        $clave = session('clave_temporal');

        return [User::where('email', mb_strtolower($datos['email']))->firstOrFail(), $clave];
    }

    private function ajena(string $rol = 'Secretaria', ?string $reg = null): User
    {
        $cuenta = User::create([
            'name' => 'Cuenta ajena', 'email' => 'ajena-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $cuenta->assignRole($rol);

        if ($reg !== null) {
            MedicoRegistro::create(['user_id' => $cuenta->id, 'reg_medico' => $reg]);
        }

        return $cuenta;
    }

    // -------------------------------------------------------------------------------------- Quién entra

    public function test_el_medico_ve_la_pantalla_vacia_y_en_el_menu(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.usuarios'));

        $respuesta->assertOk();
        $respuesta->assertSee('Usuarios');
        $respuesta->assertSee('Todavía no tienes secretaria');
        $respuesta->assertSee('Nueva secretaria');
        $respuesta->assertSee('href="' . route('clinica.usuarios') . '"', false);
        $respuesta->assertSee('href="' . route('clinica.bitacora') . '"', false);
    }

    public function test_la_secretaria_recibe_403_en_la_pantalla_y_en_cada_accion_y_no_ve_la_entrada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $otra = $this->ajena('Secretaria', $medico->reg_medico);

        $como = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $como->get(route('clinica.usuarios'))->assertForbidden();
        $como->post(route('clinica.usuarios.crear'), $this->nueva())->assertForbidden();
        $como->post(route('clinica.usuarios.restablecer', $otra->id))->assertForbidden();
        $como->post(route('clinica.usuarios.bloquear', $otra->id))->assertForbidden();
        $como->post(route('clinica.usuarios.desbloquear', $otra->id))->assertForbidden();
        $como->delete(route('clinica.usuarios.quitar', $otra->id))->assertForbidden();

        $inicio = $como->get(route('clinica.agenda'));
        $inicio->assertOk();
        $inicio->assertDontSee('href="' . route('clinica.usuarios') . '"', false);
        $inicio->assertDontSee('href="' . route('clinica.bitacora') . '"', false);
        $this->assertTrue(User::whereKey($otra->id)->where('is_active', true)->exists(), 'Ninguna acción de la secretaria tocó la cuenta.');
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get(route('clinica.usuarios'))->assertRedirect(route('login'));
        $this->post(route('clinica.usuarios.crear'), $this->nueva())->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------------------------------- El alta

    public function test_crear_la_secretaria_la_deja_vinculada_con_clave_temporal_que_se_muestra_una_sola_vez(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        [$cuenta, $datosDeLaClave] = $this->crearPorPantalla($user, $medico, $gineco, ['name' => 'María Gómez']);

        $this->assertSame('María Gómez', $cuenta->name);
        $this->assertSame(['Secretaria'], $cuenta->getRoleNames()->all(), 'Sólo el rol Secretaria.');
        $this->assertTrue((bool) $cuenta->must_change_password, 'La clave temporal obliga a cambiarla.');
        $this->assertTrue((bool) $cuenta->is_active);
        $this->assertNull($cuenta->reg_medico);
        $this->assertSame(
            [$medico->reg_medico],
            MedicoRegistro::where('user_id', $cuenta->id)->pluck('reg_medico')->all(),
            'Vinculada al reg_medico del contexto y a ningún otro.'
        );
        $this->assertNull(Medico::where('user_id', $cuenta->id)->first(), 'No se crea una ficha de médico.');

        $this->assertSame('creada', $datosDeLaClave['motivo']);
        $this->assertSame($cuenta->email, $datosDeLaClave['correo']);
        $this->assertTrue(Hash::check($datosDeLaClave['clave'], $cuenta->password), 'La clave mostrada es la que quedó guardada (hasheada).');
        $this->assertGreaterThanOrEqual(CuentaService::LARGO_MINIMO_CLAVE, strlen($datosDeLaClave['clave']));
        $this->assertNotSame($datosDeLaClave['clave'], $cuenta->password);
    }

    public function test_la_clave_se_pinta_una_vez_y_se_pierde_al_recargar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $datos = $this->nueva('Luisa Pérez');
        $primera = $this->como($user, $medico, $gineco)->followingRedirects()->post(route('clinica.usuarios.crear'), $datos);

        $primera->assertOk();
        $primera->assertSee('Clave temporal de Luisa Pérez');
        $primera->assertSee('Se muestra una sola vez');
        $primera->assertSee('Creaste la cuenta de Luisa Pérez');

        $clave = User::where('email', $datos['email'])->first();
        $this->assertNotNull($clave);

        $segunda = $this->como($user, $medico, $gineco)->get(route('clinica.usuarios'));
        $segunda->assertOk();
        $segunda->assertSee('Luisa Pérez');
        $segunda->assertDontSee('Clave temporal de Luisa Pérez');
        $segunda->assertSee('Clave temporal sin cambiar');
    }

    public function test_un_correo_que_ya_tiene_cuenta_se_rechaza_sin_adoptarla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajena = $this->ajena('Secretaria', 'otro-consultorio');

        $respuesta = $this->como($user, $medico, $gineco)->from(route('clinica.usuarios'))
            ->post(route('clinica.usuarios.crear'), ['name' => 'Otra', 'email' => strtoupper($ajena->email)]);

        $respuesta->assertRedirect(route('clinica.usuarios'));
        $respuesta->assertSessionHasErrors('email');
        $this->assertStringContainsString('no se puede reutilizar', session('errors')->first('email'));
        $this->assertSame(['otro-consultorio'], MedicoRegistro::where('user_id', $ajena->id)->pluck('reg_medico')->all(), 'La cuenta ajena no se vinculó.');

        // El correo de la ficha de un médico tampoco se puede usar.
        $this->como($user, $medico, $gineco)->from(route('clinica.usuarios'))
            ->post(route('clinica.usuarios.crear'), ['name' => 'Otra', 'email' => $user->email])
            ->assertSessionHasErrors('email');
    }

    public function test_los_datos_del_alta_se_validan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->como($user, $medico, $gineco)->from(route('clinica.usuarios'))
            ->post(route('clinica.usuarios.crear'), ['name' => '', 'email' => 'esto-no-es-un-correo']);

        $respuesta->assertSessionHasErrors(['name', 'email']);
        $errores = session('errors');
        $this->assertSame('Escribe el nombre de la secretaria.', $errores->first('name'));
        $this->assertSame('El correo no es válido.', $errores->first('email'));
        $this->assertSame(0, MedicoRegistro::whereNotNull('user_id')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_no_hay_forma_de_dar_otro_rol_ni_otro_registro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        [$cuenta] = $this->crearPorPantalla($user, $medico, $gineco, [
            'role' => 'Administrador', 'roles' => ['Root'], 'reg_medico' => 'otro-registro', 'is_active' => 0,
        ]);

        $this->assertSame(['Secretaria'], $cuenta->getRoleNames()->all());
        $this->assertSame([$medico->reg_medico], MedicoRegistro::where('user_id', $cuenta->id)->pluck('reg_medico')->all());
        $this->assertTrue((bool) $cuenta->is_active);
    }

    // ------------------------------------------------------------------------------ Restablecer / bloquear

    public function test_restablecer_la_clave_da_una_temporal_nueva_y_la_anterior_deja_de_servir(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$cuenta, $primera] = $this->crearPorPantalla($user, $medico, $gineco);
        $cuenta->forceFill(['must_change_password' => false, 'password' => Hash::make('mi-clave-propia')])->save();

        $this->como($user, $medico, $gineco)->post(route('clinica.usuarios.restablecer', $cuenta->id))
            ->assertRedirect(route('clinica.usuarios'));
        $nueva = session('clave_temporal');

        $cuenta->refresh();
        $this->assertSame('restablecida', $nueva['motivo']);
        $this->assertTrue(Hash::check($nueva['clave'], $cuenta->password));
        $this->assertFalse(Hash::check('mi-clave-propia', $cuenta->password), 'La clave anterior deja de servir.');
        $this->assertTrue((bool) $cuenta->must_change_password, 'Vuelve a obligar el cambio de clave.');
    }

    public function test_bloquear_corta_el_acceso_y_desbloquear_lo_devuelve(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$cuenta] = $this->crearPorPantalla($user, $medico, $gineco);
        $cuenta->forceFill(['must_change_password' => false])->save();

        $this->como($user, $medico, $gineco)
            ->post(route('clinica.usuarios.bloquear', $cuenta->id), ['motivo' => 'Ya no trabaja aquí'])
            ->assertRedirect(route('clinica.usuarios'));

        $cuenta->refresh();
        $this->assertFalse((bool) $cuenta->is_active);
        $this->assertSame('Ya no trabaja aquí', $cuenta->blocked_reason);

        $lista = $this->como($user, $medico, $gineco)->get(route('clinica.usuarios'));
        $lista->assertSee('Bloqueada');
        $lista->assertSee('Motivo: Ya no trabaja aquí');
        $lista->assertSee('Desbloquear');

        // Una bloqueada no entra: el middleware de cuentas bloqueadas cierra la sesión y manda al login.
        $this->conContexto($cuenta, $medico, $gineco)->actingAs($cuenta)->get(route('clinica.agenda'))->assertRedirect(route('login'));

        $this->como($user, $medico, $gineco)->post(route('clinica.usuarios.desbloquear', $cuenta->id))->assertRedirect(route('clinica.usuarios'));

        $cuenta->refresh();
        $this->assertTrue((bool) $cuenta->is_active);
        $this->assertNull($cuenta->blocked_reason);
        $this->conContexto($cuenta, $medico, $gineco)->actingAs($cuenta)->get(route('clinica.agenda'))->assertOk();
    }

    public function test_el_motivo_del_bloqueo_es_opcional_y_tiene_tope(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$cuenta] = $this->crearPorPantalla($user, $medico, $gineco);

        $this->como($user, $medico, $gineco)->post(route('clinica.usuarios.bloquear', $cuenta->id))->assertRedirect();
        $this->assertNull($cuenta->fresh()->blocked_reason);

        $this->como($user, $medico, $gineco)->from(route('clinica.usuarios'))
            ->post(route('clinica.usuarios.bloquear', $cuenta->id), ['motivo' => str_repeat('x', 201)])
            ->assertSessionHasErrors('motivo');
    }

    // ------------------------------------------------------------------------------------ Quitar el acceso

    public function test_quitar_el_acceso_no_borra_la_cuenta_y_la_deja_inactiva_sin_vinculos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$cuenta] = $this->crearPorPantalla($user, $medico, $gineco);

        $this->como($user, $medico, $gineco)->delete(route('clinica.usuarios.quitar', $cuenta->id))->assertRedirect(route('clinica.usuarios'));

        $this->assertTrue(User::whereKey($cuenta->id)->exists(), 'La cuenta no se borra.');
        $this->assertSame(0, MedicoRegistro::where('user_id', $cuenta->id)->count());
        $cuenta->refresh();
        $this->assertFalse((bool) $cuenta->is_active, 'Sin ningún consultorio queda inactiva.');
        $this->assertSame('Sin acceso a ningún consultorio.', $cuenta->blocked_reason);

        // Ya no está en la lista del médico, y no puede volver a tocarla (404).
        $this->como($user, $medico, $gineco)->get(route('clinica.usuarios'))->assertDontSee($cuenta->email);
        $this->como($user, $medico, $gineco)->post(route('clinica.usuarios.restablecer', $cuenta->id))->assertNotFound();
        // Y no entra a /clinica: o la cuenta bloqueada la corta o, sin vínculo, no tiene ningún consultorio.
        $this->conContexto($cuenta, $medico, $gineco)->actingAs($cuenta)->get(route('clinica.agenda'))->assertRedirect(route('login'));
    }

    public function test_con_mas_de_un_consultorio_el_medico_solo_quita_su_vinculo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otroMedico] = $this->medicoConAcceso();
        [$cuenta] = $this->crearPorPantalla($user, $medico, $gineco);
        MedicoRegistro::create(['user_id' => $cuenta->id, 'reg_medico' => $otroMedico->reg_medico]);

        $this->como($user, $medico, $gineco)->delete(route('clinica.usuarios.quitar', $cuenta->id))->assertRedirect(route('clinica.usuarios'));

        $this->assertSame([$otroMedico->reg_medico], MedicoRegistro::where('user_id', $cuenta->id)->pluck('reg_medico')->all());
        $cuenta->refresh();
        $this->assertTrue((bool) $cuenta->is_active, 'Sigue activa para el otro consultorio.');
        $this->assertNull($cuenta->blocked_reason);
    }

    public function test_una_cuenta_compartida_no_se_puede_restablecer_ni_bloquear_pero_si_quitar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otroMedico] = $this->medicoConAcceso();
        [$cuenta] = $this->crearPorPantalla($user, $medico, $gineco);
        MedicoRegistro::create(['user_id' => $cuenta->id, 'reg_medico' => $otroMedico->reg_medico]);
        $hashAntes = $cuenta->fresh()->password;

        $lista = $this->como($user, $medico, $gineco)->get(route('clinica.usuarios'));
        $lista->assertSee('También trabaja con otro consultorio');
        $lista->assertDontSee('Restablecer clave');
        $lista->assertDontSee('data-bloquear', false);

        $this->como($user, $medico, $gineco)->from(route('clinica.usuarios'))->post(route('clinica.usuarios.restablecer', $cuenta->id))
            ->assertSessionHasErrors('cuenta');
        $this->como($user, $medico, $gineco)->from(route('clinica.usuarios'))->post(route('clinica.usuarios.bloquear', $cuenta->id))
            ->assertSessionHasErrors('cuenta');

        $cuenta->refresh();
        $this->assertSame($hashAntes, $cuenta->password, 'La clave de una cuenta compartida no se toca.');
        $this->assertTrue((bool) $cuenta->is_active);
    }

    // --------------------------------------------------------------------------------------- Aislamiento

    public function test_una_cuenta_que_no_es_una_secretaria_del_medico_responde_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otroMedico] = $this->medicoConAcceso();

        $deOtroMedico = $this->ajena('Secretaria', $otroMedico->reg_medico);
        $administrador = $this->ajena('Administrador', $medico->reg_medico);
        $tambienMedico = $this->secretariaConAcceso($medico);
        $tambienMedico->assignRole('Medico');
        $sinVinculo = $this->ajena('Secretaria');

        foreach ([$deOtroMedico, $administrador, $tambienMedico, $sinVinculo] as $cuenta) {
            $como = $this->como($user, $medico, $gineco);
            $como->post(route('clinica.usuarios.restablecer', $cuenta->id))->assertNotFound();
            $como->post(route('clinica.usuarios.bloquear', $cuenta->id))->assertNotFound();
            $como->post(route('clinica.usuarios.desbloquear', $cuenta->id))->assertNotFound();
            $como->delete(route('clinica.usuarios.quitar', $cuenta->id))->assertNotFound();
        }

        $como = $this->como($user, $medico, $gineco)->get(route('clinica.usuarios'));
        $como->assertDontSee($deOtroMedico->email);
        $como->assertDontSee($administrador->email);
        $como->assertDontSee($tambienMedico->email);

        $this->assertTrue(User::whereKey($deOtroMedico->id)->where('is_active', true)->exists());
        $this->assertSame(1, MedicoRegistro::where('user_id', $deOtroMedico->id)->count());
        $this->assertSame(1, MedicoRegistro::where('user_id', $administrador->id)->count(), 'Su vínculo no se tocó.');
    }

    public function test_un_id_que_no_existe_responde_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->como($user, $medico, $gineco)->post(route('clinica.usuarios.bloquear', 987654321))->assertNotFound();
    }

    // -------------------------------------------------------------------------------- El servicio (la regla)

    public function test_el_servicio_lista_solo_secretarias_de_los_registros_pedidos(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $mia = $this->secretariaConAcceso($medico);
        $deOtro = $this->secretariaConAcceso($otro);
        $admin = $this->ajena('Administrador', $medico->reg_medico);

        $lista = app(CuentaService::class)->secretariasDe([$medico->reg_medico]);

        $this->assertSame([$mia->id], $lista->pluck('id')->all());
        $this->assertSame([$medico->reg_medico], $lista->first()->vinculos);
        $this->assertSame([], app(CuentaService::class)->secretariasDe([])->all());
        $this->assertNotContains($deOtro->id, $lista->pluck('id')->all());
        $this->assertNotContains($admin->id, $lista->pluck('id')->all());
    }

    public function test_el_servicio_no_crea_una_secretaria_para_un_consultorio_que_no_existe(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CuentaService::class)->crearSecretaria('Ana', 'ana-' . uniqid() . '@example.com', 'registro-inventado');
    }

    public function test_el_panel_de_administracion_sigue_viendo_la_cuenta_creada_desde_la_web(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$cuenta] = $this->crearPorPantalla($user, $medico, $gineco);

        // Es una cuenta normal para el panel: rol Secretaria, entra por la misma ruta de inicio y se le puede resetear la clave allí.
        $this->assertSame('clinica.inicio', $cuenta->rutaDeInicio());
        $clave = app(CuentaService::class)->resetearClave($cuenta);
        $this->assertTrue(Hash::check($clave, $cuenta->fresh()->password));
    }
}
