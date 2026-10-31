<?php

namespace App\Mail;

use App\Models\Pago;
use App\Pagos\Correos\DatosDeLaCitaPagada;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El aviso al **médico** de que le pagaron una cita, con el paciente, el monto, la fecha y hora y el
 * canal por el que entró el cobro. El nombre del paciente sí va: es su paciente.
 */
class PagoDeCitaAlMedico extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Pago $pago,
        public DatosDeLaCitaPagada $datos,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Te pagaron la cita del ' . $this->datos->fechaCorta)
            ->view('emails.pago-cita-medico');
    }
}
