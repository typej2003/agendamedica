<?php

namespace App\Mail\RegistroDeMedico;

use App\Models\SolicitudMedico;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** A cada administrador: llegó una solicitud para revisar. Lo manda `App\RegistroDeMedicos\CorreosDeLaSolicitud`. */
class NuevaSolicitudDeMedico extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SolicitudMedico $solicitud)
    {
    }

    public function build(): self
    {
        return $this->subject('Nueva solicitud de registro de médico')
            ->view('emails.registro-medico.nueva');
    }
}
