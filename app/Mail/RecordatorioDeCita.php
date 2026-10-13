<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El recordatorio de una cita por **correo** (WEB-2.6), con el SMTP del servidor.
 *
 * El remitente es el **médico de la cita** (su `evolucion.correo_med`, cruzado con `medicos.email`),
 * que es la decisión tomada al cerrar la pregunta abierta del correo: sale del servidor, pero el
 * paciente ve como remitente a su médico, y responderle contesta al médico. Si el médico no tiene
 * correo cargado, el `from` queda en el del servidor (`MAIL_FROM_ADDRESS`).
 */
class RecordatorioDeCita extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $cuerpo,
        public string $paciente,
        public ?string $doctor = null,
        public ?string $centro = null,
        public ?string $remitente = null,
        public ?string $nombreRemitente = null,
    ) {
    }

    public function build(): self
    {
        $this->subject('Recordatorio de cita')->view('emails.recordatorio-cita');

        if ($this->remitente) {
            // El remitente es el médico (`evolucion.correo_med`): el paciente contesta al médico, no
            // al servidor. Si su correo no está cargado, queda el `MAIL_FROM_ADDRESS` del servidor.
            $this->from($this->remitente, $this->nombreRemitente ?: null);
            $this->replyTo($this->remitente, $this->nombreRemitente ?: null);
        }

        return $this;
    }
}
