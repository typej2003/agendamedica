<?php

namespace App\Mail\RegistroDeMedico;

use App\Models\SolicitudMedico;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Al médico rechazado: el motivo, y que puede volver a postularse. Lo manda `App\RegistroDeMedicos\CorreosDeLaSolicitud`. */
class SolicitudDeMedicoRechazada extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SolicitudMedico $solicitud)
    {
    }

    public function build(): self
    {
        return $this->subject('Sobre tu solicitud de registro en Doctorisimo')
            ->view('emails.registro-medico.rechazada');
    }
}
