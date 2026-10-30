<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **La cuenta del paciente** (2026-10-19): registro con código de 6 dígitos.
 *
 * Lo que se fija acá: el alta crea el `User` y la ficha (o enlaza la que ya existía), el código se
 * comprueba, la ficha no se pisa, y los choques de cédula/correo/teléfono se rechazan antes de escribir.
 */
class RegistroDePacienteWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Carbon::setTestNow(Carbon::create(2026, 10, 19, 7, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,string> */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'cedula' => '9' . random_int(1000000, 9999999),
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'telefono' => '0414-5556677',
            'email' => 'paciente-' . uniqid() . '@example.com',
            'password' => 'Secreto123',
            'password_confirmation' => 'Secreto123',
            'terminos' => '1',
        ], $cambios);
    }

    private function codigo(): string
    {
        return (string) session('registro.pendiente.codigo');
    }

    public function test_el_registro_es_publico(): void
    {
        $this->get(route('paciente.registro'))
            ->assertOk()
            ->assertSee('Crea tu cuenta de paciente')
            ->assertSee('Continuar y verificar mi teléfono');
    }

    public function test_el_formulario_ofrece_los_terminos_y_la_privacidad_en_el_dialogo(): void
    {
        $this->get(route('paciente.registro'))
            ->assertOk()
            ->assertSee('He leído y acepto los')
            ->assertSee('términos y condiciones')
            ->assertSee('política de privacidad')
            ->assertSee(route('legal.terminos'), false)
            ->assertSee(route('legal.privacidad'), false)
            ->assertSee('Desplázate hasta el final', false)
            ->assertSee('data-aceptacion-casilla', false)
            ->assertSee('data-aceptacion-dialogo', false);
    }

    public function test_sin_aceptar_los_terminos_no_se_envia_el_registro(): void
    {
        $datos = $this->datos(['terminos' => '']);

        $this->from(route('paciente.registro'))
            ->post(route('paciente.registro.codigo.enviar'), $datos)
            ->assertRedirect(route('paciente.registro'))
            ->assertSessionHasErrors('terminos');

        $this->assertNull(session('registro.pendiente'));
        $this->assertDatabaseMissing('users', ['email' => $datos['email']]);
    }

    public function test_el_flujo_completo_crea_la_cuenta_y_enlaza_la_ficha(): void
    {
        $datos = $this->datos();

        $this->post(route('paciente.registro.codigo.enviar'), $datos)
            ->assertRedirect(route('paciente.registro.codigo'));

        $this->assertDatabaseMissing('users', ['email' => $datos['email']]);
        $this->assertDatabaseMissing('pacientes', ['cedula' => $datos['cedula']]);
        $this->assertNotEmpty($this->codigo());

        $this->post(route('paciente.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('paciente.citas'));

        $this->assertAuthenticated();

        $user = User::where('email', $datos['email'])->firstOrFail();
        $paciente = Paciente::where('cedula', $datos['cedula'])->firstOrFail();

        $this->assertTrue($user->hasRole('Paciente'));
        $this->assertTrue(Hash::check('Secreto123', $user->password));
        $this->assertSame($user->id, $paciente->user_id);
        $this->assertSame('584145556677', $paciente->telefono);

        $this->get(route('paciente.citas'))->assertOk()->assertSee('No tienes citas próximas');
    }

    public function test_una_ficha_existente_con_el_mismo_telefono_se_enlaza_sin_pisar_sus_datos(): void
    {
        $paciente = Paciente::create([
            'nombres' => 'Nombre del consultorio',
            'apellidos' => 'Apellido',
            'cedula' => '12345678',
            'telefono' => '0412-1112233',
            'email' => null,
        ]);

        $antes = Paciente::count();
        $datos = $this->datos([
            'cedula' => '12345678',
            'telefono' => '0412-1112233',
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
        ]);

        $this->post(route('paciente.registro.codigo.enviar'), $datos);
        $this->post(route('paciente.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('paciente.citas'));

        $this->assertSame($antes, Paciente::count());
        $this->assertSame('Nombre del consultorio', $paciente->fresh()->nombres, 'No se pisa el nombre que cargó el consultorio.');
        $this->assertSame($datos['email'], $paciente->fresh()->email, 'Solo se completa lo que estaba vacío.');
    }

    public function test_una_ficha_existente_con_otro_telefono_se_rechaza(): void
    {
        Paciente::create([
            'nombres' => 'Otra',
            'apellidos' => 'Persona',
            'cedula' => '12345678',
            'telefono' => '0412-1112233',
        ]);

        $antes = User::count();

        $this->from(route('paciente.registro'))
            ->post(route('paciente.registro.codigo.enviar'), $this->datos([
                'cedula' => '12345678',
                'telefono' => '0414-5556677',
            ]))
            ->assertRedirect(route('paciente.registro'))
            ->assertSessionHasErrors('telefono');

        $this->assertNull(session('registro.pendiente'));
        $this->assertSame($antes, User::count());
    }

    public function test_un_correo_que_ya_es_de_una_cuenta_se_rechaza(): void
    {
        $existente = User::create([
            'name' => 'Otra cuenta',
            'email' => 'ocupado@example.com',
            'password' => Hash::make('Secreto123'),
        ]);

        $this->from(route('paciente.registro'))
            ->post(route('paciente.registro.codigo.enviar'), $this->datos(['email' => $existente->email]))
            ->assertRedirect(route('paciente.registro'))
            ->assertSessionHasErrors('email');

        $this->assertNull(session('registro.pendiente'));
    }

    public function test_una_cedula_que_ya_tiene_cuenta_se_rechaza(): void
    {
        $user = User::create([
            'name' => 'Cuenta existente',
            'email' => 'con-cuenta@example.com',
            'password' => Hash::make('Secreto123'),
        ]);
        $user->assignRole('Paciente');

        Paciente::create([
            'nombres' => 'Con',
            'apellidos' => 'Cuenta',
            'cedula' => '12345678',
            'telefono' => '0414-5556677',
            'email' => 'con-cuenta@example.com',
            'user_id' => $user->id,
        ]);

        $this->from(route('paciente.registro'))
            ->post(route('paciente.registro.codigo.enviar'), $this->datos([
                'cedula' => '12345678',
                'email' => 'nueva@example.com',
                'telefono' => '0414-5556677',
            ]))
            ->assertRedirect(route('paciente.registro'))
            ->assertSessionHasErrors('cedula');
    }

    public function test_un_codigo_equivocado_no_crea_la_cuenta_y_deja_reintentar(): void
    {
        $datos = $this->datos();
        $this->post(route('paciente.registro.codigo.enviar'), $datos);

        $errado = $this->codigo() === '000000' ? '111111' : '000000';

        $this->from(route('paciente.registro.codigo'))
            ->post(route('paciente.registro.confirmar'), ['codigo' => $errado])
            ->assertRedirect(route('paciente.registro.codigo'))
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $datos['email']]);
        $this->assertSame(1, (int) session('registro.pendiente.intentos'));

        $this->post(route('paciente.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('paciente.citas'));

        $this->assertDatabaseHas('users', ['email' => $datos['email']]);
    }

    public function test_un_codigo_vencido_no_crea_la_cuenta(): void
    {
        $datos = $this->datos();
        $this->post(route('paciente.registro.codigo.enviar'), $datos);

        $pendiente = session('registro.pendiente');
        $pendiente['expira'] = now()->subMinute()->timestamp;
        session(['registro.pendiente' => $pendiente]);

        $this->from(route('paciente.registro.codigo'))
            ->post(route('paciente.registro.confirmar'), ['codigo' => $this->codigo()])
            ->assertRedirect(route('paciente.registro.codigo'))
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $datos['email']]);
        $this->assertNull(session('registro.pendiente'));
    }

    public function test_la_contrasena_tiene_que_coincidir_y_tener_ocho_caracteres(): void
    {
        $this->from(route('paciente.registro'))
            ->post(route('paciente.registro.codigo.enviar'), $this->datos([
                'password' => 'corta',
                'password_confirmation' => 'otra',
            ]))
            ->assertRedirect(route('paciente.registro'))
            ->assertSessionHasErrors('password');

        $this->assertNull(session('registro.pendiente'));
    }
}
