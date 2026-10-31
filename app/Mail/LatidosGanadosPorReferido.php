<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El aviso de **Latidos ganados por un referido** (fase 4): al referente y al referido, con el monto en
 * Latidos. No es un comprobante de pago: los Latidos no son dinero.
 */
class LatidosGanadosPorReferido extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombre,
        public int $latidos,
        public bool $esReferente,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Ganaste Latidos en Doctorisimo')
            ->view('emails.latidos-referido');
    }
}
