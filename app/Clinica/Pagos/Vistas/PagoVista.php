<?php

namespace App\Clinica\Pagos\Vistas;

use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use App\Pagos\Centavos;

/**
 * La pantalla de **pagar un plan**: el resumen del pago y el paso en el que está (datos del pagador, código
 * OTP, confirmando o terminado). No decide el estado: eso lo hace `Cobros`; acá sólo se lee.
 */
final class PagoVista
{
    public function __construct(
        public readonly Pago $pago,
        public readonly ?MetodoPago $metodo,
        public readonly ?TasaDeCambio $tasa,
    ) {
    }

    public function titulo(): string
    {
        return 'Pagar el plan';
    }

    public function aprobado(): bool
    {
        return $this->pago->estaAprobado();
    }

    public function fallado(): bool
    {
        return in_array($this->pago->estado, [Pago::RECHAZADO, Pago::CANCELADO, Pago::EXPIRADO], true);
    }

    public function pendiente(): bool
    {
        return $this->pago->estaPendiente();
    }

    /** Falta pedir el código: el pagador tiene que cargar sus datos. */
    public function enDatos(): bool
    {
        return $this->pago->estado === Pago::PENDIENTE && empty($this->pago->otp_enviado_el);
    }

    /** Se pidió el código y todavía no se confirmó. */
    public function enOtp(): bool
    {
        return $this->pago->estado === Pago::EN_PROCESO && (int) $this->pago->otp_intentos === 0;
    }

    /** Se confirmó y SyPago todavía no dijo el resultado final. */
    public function enConfirmacion(): bool
    {
        return $this->pago->estado === Pago::EN_PROCESO && (int) $this->pago->otp_intentos > 0;
    }

    public function esPagoMovil(): bool
    {
        return $this->metodo?->variante === 'pago_movil';
    }

    public function metodoEtiqueta(): string
    {
        return $this->metodo?->etiqueta() ?? '—';
    }

    public function montoUsd(): string
    {
        return Centavos::formatear((int) $this->pago->monto_usd_e2, 'USD');
    }

    public function montoVes(): string
    {
        return Centavos::formatear((int) $this->pago->monto_e2, (string) $this->pago->moneda);
    }

    public function tasaTexto(): string
    {
        return $this->pago->tasa === null ? '—' : number_format((float) $this->pago->tasa, 4, ',', '.');
    }

    public function estadoLegible(): string
    {
        return match ($this->pago->estado) {
            Pago::PENDIENTE => 'Esperando tus datos',
            Pago::EN_PROCESO => $this->enOtp() ? 'Esperando el código' : 'Confirmando el pago',
            Pago::APROBADO => 'Pago aprobado',
            Pago::RECHAZADO => 'Pago rechazado',
            Pago::EXPIRADO => 'Pago vencido',
            Pago::CANCELADO => 'Pago cancelado',
            default => ucfirst((string) $this->pago->estado),
        };
    }

    public function estadoTono(): string
    {
        return match ($this->pago->estado) {
            Pago::APROBADO => 'success',
            Pago::EN_PROCESO => 'info',
            Pago::PENDIENTE => 'secondary',
            default => 'danger',
        };
    }

    public function venceEl(): ?string
    {
        return $this->pago->expira_el?->format('d/m/Y H:i');
    }
}
