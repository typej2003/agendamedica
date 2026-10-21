<?php

namespace App\Clinica\Pagos\Vistas;

use App\Models\MetodoPago;
use App\Models\Pago;
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
        /** @var array<string,string> Los datos del último pago aprobado, para prellenar el formulario. */
        public readonly array $recordado = [],
    ) {
    }

    /** Un dato recordado del último pago, o el valor por defecto si no hay. */
    public function recordado(string $campo, string $porDefecto = ''): string
    {
        $valor = $this->recordado[$campo] ?? null;

        return is_string($valor) && trim($valor) !== '' ? $valor : $porDefecto;
    }

    /** La cuenta o el teléfono recordado (el pago pudo haberse hecho con cualquiera de los dos). */
    public function cuentaRecordada(): string
    {
        return $this->recordado('cuenta') ?: $this->recordado('telefono');
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
        return in_array($this->pago->estado, [Pago::RECHAZADO, Pago::CANCELADO], true);
    }

    public function pendiente(): bool
    {
        return $this->pago->estaPendiente();
    }

    /** Falta pedir el código: el pagador tiene que cargar sus datos. Un vencido se puede renovar desde el checkout. */
    public function enDatos(): bool
    {
        return in_array($this->pago->estado, [Pago::PENDIENTE, Pago::EXPIRADO], true)
            && empty($this->pago->otp_enviado_el);
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

    public function metodoEtiqueta(): string
    {
        return $this->metodo?->etiqueta() ?? '—';
    }

    public function montoUsd(): string
    {
        return Centavos::formatear((int) $this->pago->monto_usd_e2, 'USD');
    }

    public function monto(): string
    {
        return Centavos::formatear((int) $this->pago->monto_e2, (string) $this->pago->moneda);
    }

    public function estadoLegible(): string
    {
        return match ($this->pago->estado) {
            Pago::PENDIENTE => 'Esperando tus datos',
            Pago::EN_PROCESO => $this->enOtp() ? 'Esperando el código' : 'Confirmando el pago',
            Pago::APROBADO => 'Pago aprobado',
            Pago::RECHAZADO => 'Pago rechazado',
            Pago::EXPIRADO => 'Esperando tus datos',
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
            Pago::EXPIRADO => 'secondary',
            default => 'danger',
        };
    }

    /** Cuántos segundos le quedan al pago para poder iniciarse; 0 si ya venció. */
    public function segundosRestantes(): int
    {
        if (! $this->pago->expira_el) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->pago->expira_el, false));
    }
}
