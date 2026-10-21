<?php

namespace App\Mail\RegistroDeMedico;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** El código de 6 dígitos que verifica el correo del médico que se registra. Lo manda `App\RegistroDeMedicos\CorreosDeLaSolicitud`. */
class CodigoDeRegistroDeMedico extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $nombre, public string $codigo)
    {
    }

    public function build(): self
    {
        return $this->subject('Tu código para registrarte en Doctorisimo')
            ->view('emails.registro-medico.codigo');
    }
}
