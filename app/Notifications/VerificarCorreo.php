<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * El correo de **verificar el correo electrónico**, en español.
 *
 * Extiende la notificación del framework y sólo reescribe el texto: `verificationUrl()` sigue armando la
 * URL firmada de `verification.verify` con el vencimiento de `auth.verification.expire`, así que el botón
 * verifica la cuenta por el mismo camino de siempre.
 *
 * El armazón markdown (saludo, botón y pie) es el de Laravel; los textos del armazón se traducen en
 * `resources/lang/es.json`.
 */
class VerificarCorreo extends VerifyEmail
{
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifica tu correo')
            ->greeting('Hola')
            ->line('Haz clic en el botón para verificar tu correo electrónico.')
            ->action('Verificar mi correo', $url)
            ->line('Si no creaste una cuenta, no tienes que hacer nada.')
            ->salutation('Saludos,');
    }
}
