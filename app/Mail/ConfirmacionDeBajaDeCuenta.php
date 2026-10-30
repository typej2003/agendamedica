<?php

namespace App\Mail;

use App\Models\SolicitudDeBaja;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * **El correo que confirma una baja de cuenta** (2026-10-30): el enlace que la pone en marcha y el que la
 * cancela.
 *
 * Los dos enlaces son la única prueba de que el pedido lo hizo el dueño del correo: sin abrir el primero,
 * la baja no arranca.
 */
class ConfirmacionDeBajaDeCuenta extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SolicitudDeBaja $solicitud)
    {
    }

    public function build(): self
    {
        return $this->subject('Confirma la baja de tu cuenta de Doctorísimo')
            ->view('emails.baja-confirmar', [
                'confirmar' => route('legal.baja.confirmar', $this->solicitud->token),
                'cancelar'  => route('legal.baja.cancelar', $this->solicitud->token),
                'dias'      => (int) config('legal.dias_de_gracia_de_la_baja', 30),
            ]);
    }
}
