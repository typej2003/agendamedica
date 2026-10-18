<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **El login es uno solo** (2026-10-19): correo y contraseña, sin elegir médico / paciente /
 * administrador. El tipo de cuenta lo resuelve `App\Services\AutenticacionPorCorreo` probando `users`,
 * `medicos` y `pacientes` en ese orden.
 *
 * Lo que se fija acá:
 *  - la pantalla ya no tiene selector y el POST funciona sin `user_type`;
 *  - entran las tres clases de cuenta de siempre, al mismo destino de siempre;
 *  - un error de credenciales no revela si el correo existe;
 *  - una cuenta con **dos** áreas elige dónde entrar, y una con una sola no ve la pregunta.
 *
 * `DatabaseTransactions` por la razón de siempre (ver MedicAPI/AGENTS.md).
 */
class LoginUnificadoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol, string $clave = 'secreto123'): User
    {
        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'login-' . uniqid() . '@example.com',
            'password'             => Hash::make($clave),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function medico(string $clave = 'secreto123', array $extra = []): Medico
    {
        $sufijo = uniqid();

        return Medico::create(array_merge([
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Login',
            'email'      => "login-medico-{$sufijo}@example.com",
            'password'   => Hash::make($clave),
            'reg_medico' => "test-login-{$sufijo}",
        ], $extra));
    }

    private function paciente(string $clave = 'secreto123'): Paciente
    {
        return Paciente::create([
            'nombres'   => 'Paciente',
            'apellidos' => 'De Prueba',
            'email'     => 'login-paciente-' . uniqid() . '@example.com',
            'password'  => Hash::make($clave),
        ]);
    }

    public function test_el_login_ya_no_pide_el_tipo_de_usuario(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="user_type"', $html, 'El formulario no debe mandar `user_type`.');
        $this->assertStringNotContainsString('systemAccessCheck', $html, 'El interruptor de «acceso de sistema» ya no existe.');
        $this->assertStringNotContainsString('type_medico', $html, 'Ya no hay selector de médico / paciente.');
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
    }

    public function test_el_medico_del_legado_entra_sin_elegir_medico(): void
    {
        $medico = $this->medico();

        $this->post('/login', [
            'email'    => $medico->email,
            'password' => 'secreto123',
        ])->assertRedirect(route('clinica.inicio'));

        $this->assertAuthenticated();

        $user = User::where('email', $medico->email)->firstOrFail();
        $this->assertSame($user->id, $medico->fresh()->user_id);
        $this->assertTrue($user->hasRole('Medico'));
    }

    public function test_la_administracion_entra_al_panel(): void
    {
        $root = $this->usuario('Root');

        $this->post('/login', [
            'email'    => $root->email,
            'password' => 'secreto123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($root->fresh());
    }

    public function test_la_secretaria_entra_al_consultorio(): void
    {
        $secretaria = $this->usuario('Secretaria');

        $this->post('/login', [
            'email'    => $secretaria->email,
            'password' => 'secreto123',
        ])->assertRedirect(route('clinica.inicio'));

        $this->assertAuthenticatedAs($secretaria->fresh());
    }

    public function test_el_paciente_del_legado_entra_y_queda_con_su_rol(): void
    {
        $paciente = $this->paciente();

        $this->post('/login', [
            'email'    => $paciente->email,
            'password' => 'secreto123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        $user = User::where('email', $paciente->email)->firstOrFail();
        $this->assertSame($user->id, $paciente->fresh()->user_id);
        $this->assertTrue($user->hasRole('Paciente'));
    }

    public function test_el_correo_no_distingue_mayusculas(): void
    {
        $user = $this->usuario('Root');
        $user->forceFill(['email' => 'Login-Mayusculas-' . uniqid() . '@Example.com'])->save();

        $this->post('/login', [
            'email'    => mb_strtolower($user->email),
            'password' => 'secreto123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_un_medico_desactivado_no_entra_por_el_camino_del_legado(): void
    {
        $medico = $this->medico('secreto123', ['is_active' => false]);

        $this->from('/login')->post('/login', [
            'email'    => $medico->email,
            'password' => 'secreto123',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $medico->email]);
    }

    public function test_las_credenciales_incorrectas_no_revelan_si_el_correo_existe(): void
    {
        $user = $this->usuario('Root');

        // La clave no coincide…
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'mala'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos.']);

        // …y el correo no existe. Mismo mensaje.
        $this->from('/login')->post('/login', ['email' => 'nadie-' . uniqid() . '@example.com', 'password' => 'mala'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos.']);

        $this->assertGuest();
    }

    public function test_una_cuenta_con_dos_areas_elige_donde_entrar(): void
    {
        $user = $this->usuario('Medico');
        $user->assignRole('Administrador');

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'secreto123',
        ])->assertRedirect(route('elegir-panel'));

        $this->get(route('elegir-panel'))
            ->assertOk()
            ->assertSee('¿Dónde quieres entrar?')
            ->assertSee('Administración')
            ->assertSee('Consultorio');
    }

    public function test_quien_tiene_una_sola_area_no_ve_la_pregunta(): void
    {
        $medico = $this->usuario('Medico');

        $this->actingAs($medico)
            ->get(route('elegir-panel'))
            ->assertRedirect(route('clinica.inicio'));

        $root = $this->usuario('Root');

        $this->actingAs($root)
            ->get(route('elegir-panel'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_la_pregunta_pide_sesion(): void
    {
        $this->get(route('elegir-panel'))->assertRedirect(route('login'));
    }

    public function test_el_registro_de_cuentas_esta_cerrado(): void
    {
        // El `/register` de Laravel creaba una cuenta sin rol; la web no lo ofrece y esta fase no da de
        // alta a nadie (el registro de médicos con aprobación es la fase siguiente).
        $this->get('/register')->assertNotFound();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('register'));
    }
}
