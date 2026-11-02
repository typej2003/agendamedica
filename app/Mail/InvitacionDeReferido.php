<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * **La invitación a un colega por correo** (O-50, 2026-11-02), desde «Invita y gana».
 *
 * La manda la plataforma, no el médico: el `from` es el remitente global (`MAIL_FROM_ADDRESS`) y **no**
 * se pone `reply-to`, porque el médico no es el que responde ese buzón. Lo que sí viaja es su nombre en
 * el texto, que es lo que le dice al colega quién lo invitó.
 *
 * No lleva datos clínicos ni del paciente: sólo el nombre del médico, el link de referido y el premio.
 */
class InvitacionDeReferido extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $invitador,
        public string $url,
        public int $latidosReferente = 0,
        public int $latidosReferido = 0,
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->invitador . ' te invita a Doctorísimo')
            ->view('emails.invitacion-referido');
    }
}
