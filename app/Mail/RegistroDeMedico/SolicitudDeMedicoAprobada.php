<?php

namespace App\Mail\RegistroDeMedico;

use App\Models\SolicitudMedico;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Al médico aprobado: el correo con el que entra y su clave temporal (la cambia al primer inicio) o, si la cuenta
 * ya existía —un paciente o un administrador que se registró como médico—, el aviso de que su área quedó
 * habilitada y que entra con su clave de siempre. Lo manda `App\RegistroDeMedicos\CorreosDeLaSolicitud`.
 */
class SolicitudDeMedicoAprobada extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SolicitudMedico $solicitud, public ?string $clave = null)
    {
    }

    public function build(): self
    {
        return $this->subject($this->clave
                ? 'Tu cuenta de Doctorisimo está lista'
                : 'Tu cuenta de Doctorisimo ya tiene el área médica')
            ->view('emails.registro-medico.aprobada');
    }
}
