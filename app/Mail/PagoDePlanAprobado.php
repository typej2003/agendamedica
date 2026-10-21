<?php

namespace App\Mail;

use App\Models\Pago;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El **comprobante por correo** de un plan pagado con SyPago (MVP, 2026-10-21): qué plan, cuánto se cobró
 * en bolívares y en dólares, con qué tasa y con qué referencia.
 *
 * Se manda después de que la transacción del pago quedó confirmada; si el correo falla, el pago sigue
 * aprobado y el error se registra (no se pierde la plata por un SMTP caído).
 */
class PagoDePlanAprobado extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Pago $pago,
        public readonly ?string $nombre = null,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Tu plan de Doctorisimo está renovado')
            ->view('emails.pago-plan-aprobado');
    }
}
