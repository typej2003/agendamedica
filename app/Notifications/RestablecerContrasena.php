<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * El correo de **restablecer la contraseña**, en español.
 *
 * Extiende la notificación del framework y sólo reescribe el texto: `resetUrl()` sigue armando el enlace
 * con `route('password.reset', ['token' => …, 'email' => …])`, así que el botón lleva a la misma pantalla
 * de siempre y se respeta `ResetPassword::createUrlUsing()` si algún día se usa.
 *
 * El armazón markdown (saludo, botón y pie) es el de Laravel; los textos del armazón se traducen en
 * `resources/lang/es.json`.
 */
class RestablecerContrasena extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutos = (int) config('auth.passwords.' . config('auth.defaults.passwords') . '.expire');

        return (new MailMessage)
            ->subject('Restablece tu contraseña')
            ->greeting('Hola')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $url)
            ->line($minutos === 1
                ? 'Este enlace vence en 1 minuto.'
                : "Este enlace vence en {$minutos} minutos.")
            ->line('Si no pediste el cambio, no tienes que hacer nada.')
            ->salutation('Saludos,');
    }
}
