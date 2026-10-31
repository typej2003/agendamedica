<?php

namespace App\Mail;

use App\Models\Pago;
use App\Pagos\Correos\DatosDeLaCitaPagada;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El aviso al **paciente** de que su pago se recibió y su cita quedó agendada.
 *
 * Sólo lleva lo necesario para llegar (médico, especialidad, día, hora, sede y dirección) y el
 * comprobante (monto y referencia). No incluye el motivo de la consulta ni ningún dato clínico, y no
 * enlaza a la pantalla de pago: el token no viaja por correo.
 */
class PagoDeCitaAlPaciente extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Pago $pago,
        public DatosDeLaCitaPagada $datos,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Pago recibido: cita del ' . $this->datos->fechaCorta . ' a las ' . $this->datos->hora)
            ->view('emails.pago-cita-paciente');
    }
}
