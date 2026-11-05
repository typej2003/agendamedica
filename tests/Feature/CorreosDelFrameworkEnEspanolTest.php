<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RestablecerContrasena;
use App\Notifications\VerificarCorreo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * **Los dos correos que arma el framework, en español**: restablecer la contraseña y verificar el correo.
 *
 * Lo que se fija acá:
 *  - el HTML que sale de verdad —el markdown de Laravel con su botón—, no sólo el `MailMessage`;
 *  - que no quede ningún texto en inglés, incluido el pie del armazón («All rights reserved.» y el
 *    «If you're having trouble clicking…») y la despedida;
 *  - que el enlace de restablecer siga siendo `route('password.reset', …)` con el token y el correo;
 *  - que el de verificación siga siendo la URL firmada de `verification.verify`;
 *  - que `User` mande estas dos notificaciones y no las del framework.
 *
 * `DatabaseTransactions`, como el resto de los tests con base.
 */
class CorreosDelFrameworkEnEspanolTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function usuario(): User
    {
        return User::create([
            'name' => 'Ana de Prueba',
            'email' => 'correos-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
    }

    /**
     * Los textos en inglés que traía el correo del framework. Si aparece cualquiera, la traducción quedó
     * a medias.
     *
     * @return array<int, string>
     */
    private function textosEnIngles(): array
    {
        return [
            'Hello!',
            'Whoops!',
            'Regards,',
            'All rights reserved',
            "If you're having trouble",
            'Reset your password',
            'Reset Password',
            'You are receiving this email',
            'This password reset link',
            'If you did not request a password reset',
            'Verify your email address',
            'Verify Email Address',
            'Please click the button',
            'If you did not create an account',
        ];
    }

    private function assertSinIngles(string $html): void
    {
        foreach ($this->textosEnIngles() as $texto) {
            $this->assertStringNotContainsString($texto, $html, "Quedó en inglés: {$texto}");
        }

        foreach (['{{', '}}', '@if', '@endif', '@lang'] as $resto) {
            $this->assertStringNotContainsString($resto, $html);
        }
    }

    // --------------------------------------------------------------------------------------- Pruebas

    public function test_el_correo_de_restablecer_contrasena_sale_en_espanol(): void
    {
        $user = $this->usuario();
        $minutos = (int) config('auth.passwords.' . config('auth.defaults.passwords') . '.expire');

        $mensaje = (new RestablecerContrasena('token-de-prueba'))->toMail($user);
        $html = (string) $mensaje->render();

        $this->assertSame('Restablece tu contraseña', $mensaje->subject);
        $this->assertSame(
            url(route('password.reset', ['token' => 'token-de-prueba', 'email' => $user->email], false)),
            $mensaje->actionUrl,
        );

        foreach ([
            'Hola',
            'Recibimos una solicitud para restablecer la contraseña de tu cuenta.',
            'Restablecer contraseña',
            "Este enlace vence en {$minutos} minutos.",
            'Si no pediste el cambio, no tienes que hacer nada.',
            'Saludos,',
            'Si tienes problemas para hacer clic en el botón «Restablecer contraseña»',
            'copia y pega el enlace de abajo en tu navegador',
            'Todos los derechos reservados.',
        ] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        $this->assertSinIngles($html);
    }

    public function test_el_correo_de_verificacion_sale_en_espanol(): void
    {
        $user = $this->usuario();

        $mensaje = (new VerificarCorreo())->toMail($user);
        $html = (string) $mensaje->render();

        $this->assertSame('Verifica tu correo', $mensaje->subject);
        $this->assertStringContainsString('email/verify/' . $user->id . '/', $mensaje->actionUrl);
        $this->assertStringContainsString('signature=', $mensaje->actionUrl);
        $this->assertTrue(URL::hasValidSignature(Request::create($mensaje->actionUrl)));

        foreach ([
            'Hola',
            'Haz clic en el botón para verificar tu correo electrónico.',
            'Verificar mi correo',
            'Si no creaste una cuenta, no tienes que hacer nada.',
            'Saludos,',
            'Si tienes problemas para hacer clic en el botón «Verificar mi correo»',
            'copia y pega el enlace de abajo en tu navegador',
            'Todos los derechos reservados.',
        ] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        $this->assertSinIngles($html);
    }

    public function test_el_usuario_manda_las_dos_notificaciones_propias(): void
    {
        Notification::fake();

        $user = $this->usuario();
        $user->sendPasswordResetNotification('token-de-prueba');
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo(
            $user,
            RestablecerContrasena::class,
            fn (RestablecerContrasena $notificacion) => $notificacion->token === 'token-de-prueba',
        );
        Notification::assertSentTo($user, VerificarCorreo::class);
    }
}
