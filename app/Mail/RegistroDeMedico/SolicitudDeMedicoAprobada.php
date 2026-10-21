<?php

namespace App\Mail\RegistroDeMedico;

use App\Models\SolicitudMedico;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Al médico aprobado: el correo con el que entra y su clave temporal (la cambia al primer inicio). Lo manda `App\RegistroDeMedicos\CorreosDeLaSolicitud`. */
class SolicitudDeMedicoAprobada extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SolicitudMedico $solicitud, public string $clave)
    {
    }

    public function build(): self
    {
        return $this->subject('Tu cuenta de Doctorisimo está lista')
            ->view('emails.registro-medico.aprobada');
    }
}
