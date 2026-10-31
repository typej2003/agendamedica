<?php

namespace App\Pagos\Vistas;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use App\Pagos\Centavos;
use App\Pagos\ClienteSyPago;
use App\Support\HoraLocal;

/**
 * **La pantalla pública de pago de una cita** (`/pagar/{token}`): qué se puede mostrar y en qué paso está
 * el pago. No decide el estado ni habla con SyPago: eso lo hace `App\Pagos\Cobros`; acá sólo se lee el
 * pago y se responde a las preguntas de la vista.
 *
 * El orden de las guardas es el del pedido y está en la vista: aprobado, vencido, rechazado/cancelado,
 * cita ya pagada por otro pago aprobado, código OTP y, al final, los datos del pagador.
 *
 * **Privacidad:** esta clase no lee el paciente. Del pago sólo expone el concepto, los montos y la
 * referencia, y de la cita sólo al médico, la fecha y la hora. Nada de cédula, teléfono, número de
 * historia ni nombre del paciente.
 */
final class PagoPublicoVista
{
    /**
     * Los bancos del débito inmediato, si SyPago los devolvió. Vacío significa que el formulario cae al
     * campo libre de cuatro dígitos.
     *
     * @var list<array{codigo:string,nombre:string}>
     */
    public array $bancos = [];

    public function __construct(
        public readonly Pago $pago,
        public readonly ?Cola $cita,
        public readonly ?Medico $medico,
        /** ¿Hay otro pago aprobado para la misma cita? */
        public readonly bool $otroPagoAprobado,
    ) {
    }

    public function titulo(): string
    {
        return 'Paga tu cita';
    }

    public function token(): string
    {
        return (string) $this->pago->token_publico;
    }

    public function concepto(): string
    {
        return (string) $this->pago->concepto;
    }

    public function medicoNombre(): string
    {
        return $this->medico?->nombre_mostrar ?? '';
    }

    public function fechaCita(): string
    {
        return $this->cita?->fecha?->format('d/m/Y') ?? '';
    }

    /** La hora de pared de la cita (`cola.hora_ini`), en el reloj de quien mira. */
    public function horaCita(): string
    {
        return HoraLocal::reloj($this->cita?->hora_ini, '');
    }

    public function monto(): string
    {
        return Centavos::formatear($this->montoE2DelDia(), (string) $this->pago->moneda);
    }

    public function montoUsd(): string
    {
        return Centavos::formatear((int) $this->pago->monto_usd_e2, 'USD');
    }

    /**
     * ¿El monto se calcula con la tasa de hoy? Sí mientras el pago de una cita siga pendiente en
     * bolívares: **el pago de una cita no congela la tasa**, se cobra con la del momento.
     */
    public function montoEsDelDia(): bool
    {
        // Sólo antes de pedir el OTP: desde `en_proceso` el monto ya viajó a SyPago y es el que se cobra.
        return $this->pago->estado === Pago::PENDIENTE
            && strtoupper((string) $this->pago->moneda) === 'VES';
    }

    /** El monto que se cobraría ahora: el de la tasa vigente si sigue pendiente, el cobrado si ya pasó. */
    private function montoE2DelDia(): int
    {
        if (! $this->montoEsDelDia()) {
            return (int) $this->pago->monto_e2;
        }

        $monto = TasaDeCambio::actual()?->convertirUsdAVes((int) $this->pago->monto_usd_e2) ?? 0;

        return $monto > 0 ? $monto : (int) $this->pago->monto_e2;
    }

    /** La tasa VES/USD que se aplicaría (o se aplicó), o `null` si el cobro fue en dólares. */
    public function tasa(): ?string
    {
        $tasa = $this->montoEsDelDia()
            ? (TasaDeCambio::actual()?->tasa ?? $this->pago->tasa)
            : $this->pago->tasa;

        if ($tasa === null || (float) $tasa <= 0) {
            return null;
        }

        return number_format((float) $tasa, 2, ',', '.') . ' VES por USD';
    }

    public function referencia(): string
    {
        $referencia = $this->pago->referencia_externa ?: $this->pago->internal_id ?: $this->pago->referencia;

        return $referencia !== null && trim((string) $referencia) !== '' ? (string) $referencia : '—';
    }

    public function fechaPago(): string
    {
        return HoraLocal::fechaHora($this->pago->pagado_el, '—');
    }

    public function aprobado(): bool
    {
        return $this->pago->estaAprobado();
    }

    /** El link venció, sea porque el pago quedó `expirado` o porque pasó `expira_el`. */
    public function vencido(): bool
    {
        return $this->pago->estado === Pago::EXPIRADO
            || ($this->pago->expira_el !== null && $this->pago->expira_el->isPast());
    }

    public function fallado(): bool
    {
        return in_array($this->pago->estado, [Pago::RECHAZADO, Pago::CANCELADO], true);
    }

    public function citaYaPagada(): bool
    {
        return $this->otroPagoAprobado;
    }

    /**
     * La cita **ya no debe nada**: se cobró entera en el mostrador (o por otro medio) y este link
     * cobraría de más. Es distinto de `citaYaPagada()`, que mira los pagos de la pasarela: acá manda
     * `cola.monto`/`monto_pagado`, que es lo que ve la agenda.
     *
     * Sin precio en la cita no se afirma nada: la cita puede estar sin monto y todavía haber algo que
     * cobrar, así que el link sigue disponible.
     */
    public function citaSinSaldo(): bool
    {
        if ($this->cita === null) {
            return false;
        }

        $precio = (float) ($this->cita->monto ?? 0);

        return $precio > 0 && $this->cita->montoPendiente() <= 0.004;
    }

    /** Faltan los datos del pagador: se muestra el formulario del débito. */
    public function enDatos(): bool
    {
        return $this->pago->estaPendiente() && empty($this->pago->datos_pagador);
    }

    /** Ya se pidió el código y se puede escribir. */
    public function enOtp(): bool
    {
        return $this->pago->estaPendiente()
            && ! empty($this->pago->datos_pagador)
            && ! $this->enConfirmacion();
    }

    /**
     * SyPago recibió la confirmación y todavía no dijo el resultado final (`PEND`/`PROC`). Un intento
     * fallido no cae acá: vuelve al formulario del código para poder reintentarlo.
     */
    public function enConfirmacion(): bool
    {
        if ($this->pago->estado !== Pago::EN_PROCESO || (int) $this->pago->otp_intentos === 0) {
            return false;
        }

        return ClienteSyPago::estadoDe((array) ($this->pago->respuesta ?? [])) === ClienteSyPago::EN_PROCESO;
    }

    /**
     * El pago se puede iniciar: sigue pendiente, el link no venció, la cita no se pagó por otro pago de
     * la pasarela y la cita todavía debe algo.
     */
    public function puedePagar(): bool
    {
        return $this->pago->estaPendiente()
            && ! $this->vencido()
            && ! $this->otroPagoAprobado
            && ! $this->citaSinSaldo();
    }

    /** Se puede confirmar el OTP: hay datos del pagador y el pago sigue vigente. */
    public function puedeConfirmar(): bool
    {
        return $this->puedePagar() && ! empty($this->pago->datos_pagador);
    }
}
