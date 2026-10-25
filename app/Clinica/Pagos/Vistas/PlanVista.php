<?php

namespace App\Clinica\Pagos\Vistas;

use App\Models\Plan;
use App\Pagos\Centavos;
use Illuminate\Support\Collection;

/**
 * La pantalla **«Mi plan»** del médico: qué servicio tiene, hasta cuándo, y el catálogo para renovarlo.
 *
 * No decide nada: el estado sale de `ServicioService` (el mismo que bloquea el sync) y los planes del
 * catálogo visible. **No muestra la tasa ni los métodos de pago**: eso se elige en la pantalla siguiente,
 * cuando el médico ya eligió el plan.
 */
final class PlanVista
{
    /**
     * @param  array<string,mixed>  $estado  el de `ServicioService::estado()`
     * @param  Collection<int,Plan>  $planes
     */
    public function __construct(
        public readonly string $regMedico,
        public readonly array $estado,
        public readonly Collection $planes,
    ) {
    }

    public function titulo(): string
    {
        return 'Mi plan';
    }

    public function subtitulo(): string
    {
        return 'Tu suscripción a Doctorisimo: qué plan tienes, hasta cuándo y cómo renovarlo.';
    }

    public function tieneServicio(): bool
    {
        return ($this->estado['estado'] ?? 'sin_servicio') !== 'sin_servicio';
    }

    public function planActual(): string
    {
        return (string) ($this->estado['plan'] ?? '—');
    }

    public function venceEl(): ?string
    {
        return $this->estado['vence_el'] ?? null;
    }

    public function diasRestantes(): ?int
    {
        return isset($this->estado['dias_restantes']) ? (int) $this->estado['dias_restantes'] : null;
    }

    public function estadoLegible(): string
    {
        return match ($this->estado['estado'] ?? 'sin_servicio') {
            'vigente' => 'Vigente',
            'gracia' => 'En gracia',
            'vencido' => 'Vencido',
            default => 'Sin plan',
        };
    }

    public function estadoTono(): string
    {
        return match ($this->estado['estado'] ?? 'sin_servicio') {
            'vigente' => 'success',
            'gracia' => 'warning',
            'vencido' => 'danger',
            default => 'secondary',
        };
    }

    public function aviso(): ?string
    {
        return $this->planes->isEmpty()
            ? 'Todavía no hay planes disponibles para contratar.'
            : null;
    }

    public function precioUsdTexto(Plan $plan): string
    {
        return Centavos::formatear(Centavos::deDecimal((string) $plan->precio_usd), 'USD');
    }

    /** El precio anterior, el que se muestra tachado cuando el plan tiene descuento. */
    public function precioTachadoUsdTexto(Plan $plan): string
    {
        return Centavos::formatear(Centavos::deDecimal((string) $plan->precio_tachado_usd), 'USD');
    }

    public function ahorroTexto(Plan $plan): string
    {
        return Centavos::formatear(Centavos::deDecimal((string) $plan->ahorroUsd()), 'USD');
    }
}
