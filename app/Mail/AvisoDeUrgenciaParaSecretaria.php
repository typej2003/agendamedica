<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El aviso a la secretaría cuando el chatbot de WhatsApp detecta una posible urgencia y hoy no hay horario
 * (`App\Reservas\AvisoDeUrgenciaSinHorario`). **Sin datos clínicos**: ni el texto del mensaje ni síntomas; sólo
 * quién escribe, su teléfono y la hora.
 */
class AvisoDeUrgenciaParaSecretaria extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?string $paciente,
        public string $telefono,
        public string $hora,
        public ?string $medico = null,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Paciente con posible urgencia: sin horario hoy')
            ->view('emails.aviso-urgencia-secretaria');
    }
}
