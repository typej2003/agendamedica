<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El saludo de cumpleaños por **correo** (WEB-2.9), con el SMTP del servidor.
 *
 * Es deliberadamente igual a `RecordatorioDeCita` —mismos campos, mismo remitente, misma vista—: el
 * `App\Notificaciones\DespachadorDeCanales` manda los dos correos con la misma forma, y el remitente es
 * el **médico** (`evolucion.correo_med` → `medicos.email`) para que el paciente le conteste a él. Lo
 * único que cambia es el asunto.
 *
 * Los datos del paciente —`cuerpo`, `paciente`, `doctor`, `centro`— se reciben aunque el saludo solo use
 * el nombre: es el mismo contrato que el recordatorio, y duplicar la forma obligaría al despachador a
 * saber cuál de los dos está mandando.
 */
class SaludoDeCumpleanos extends Mailable
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
        $this->subject('¡Feliz cumpleaños!')->view('emails.saludo-cumpleanos');

        if ($this->remitente) {
            $this->from($this->remitente, $this->nombreRemitente ?: null);
            $this->replyTo($this->remitente, $this->nombreRemitente ?: null);
        }

        return $this;
    }
}
