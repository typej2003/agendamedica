<?php

namespace App\Mail\RegistroDeMedico;

use App\Models\SolicitudMedico;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Al médico: su solicitud quedó en revisión. Lo manda `App\RegistroDeMedicos\CorreosDeLaSolicitud`. */
class SolicitudDeMedicoRecibida extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SolicitudMedico $solicitud)
    {
    }

    public function build(): self
    {
        return $this->subject('Recibimos tu solicitud de registro')
            ->view('emails.registro-medico.recibida');
    }
}
