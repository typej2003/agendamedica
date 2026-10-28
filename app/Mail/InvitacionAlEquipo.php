<?php

namespace App\Mail;

use App\Models\InvitacionDeMedico;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * La invitación de un médico a otro para unirse a su equipo (2026-10-28).
 *
 * No lleva datos clínicos: sólo quién invita, el consultorio, el enlace y el vencimiento.
 */
class InvitacionAlEquipo extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public InvitacionDeMedico $invitacion,
        public string $equipo,
        public string $invitador,
        public string $url,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Te invitaron al equipo de ' . $this->equipo)
            ->view('emails.invitacion-equipo');
    }
}
