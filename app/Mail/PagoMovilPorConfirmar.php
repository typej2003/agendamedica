<?php

namespace App\Mail;

use App\Models\Pago;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El aviso de que **un paciente declaró un pago móvil que hay que confirmar** (o que se confirmó solo y no
 * se pudo verificar). Va al médico o, si recauda la plataforma, a los administradores.
 *
 * No lleva datos clínicos: concepto, monto, pagador y referencia, más el enlace a la pantalla de cobros.
 */
class PagoMovilPorConfirmar extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Pago $pago)
    {
    }

    public function build(): self
    {
        return $this->subject('Tienes un pago por confirmar')
            ->view('emails.pago-movil-por-confirmar');
    }
}
