<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El **código de 6 dígitos** que confirma el teléfono del paciente en el portal público, cuando el SMS y el
 * WhatsApp no están disponibles (O-54).
 *
 * No lleva nada más que el código y cuánto dura: ni datos clínicos, ni el nombre del médico, ni el motivo de
 * la consulta. Lo manda `App\Reservas\CodigoDeVerificacion`.
 */
class CodigoDeReserva extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $codigo, public int $minutos = 10)
    {
    }

    public function build(): self
    {
        return $this->subject('Tu código para confirmar la cita')
            ->view('emails.codigo-de-reserva');
    }
}
