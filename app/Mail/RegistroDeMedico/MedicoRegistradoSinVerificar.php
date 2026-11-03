<?php

namespace App\Mail\RegistroDeMedico;

use App\Models\SolicitudMedico;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A cada administrador: **un médico se registró y ya tiene acceso** porque la política no exige el carnet
 * (2026-11-03). No es una solicitud «para revisar»: el carnet quedó pendiente o sin verificar y hay que
 * validarlo desde la bandeja. Lo manda `App\RegistroDeMedicos\CorreosDeLaSolicitud`.
 */
class MedicoRegistradoSinVerificar extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SolicitudMedico $solicitud)
    {
    }

    public function build(): self
    {
        return $this->subject('Un médico se registró sin carnet verificado')
            ->view('emails.registro-medico.registrado-sin-verificar');
    }
}
