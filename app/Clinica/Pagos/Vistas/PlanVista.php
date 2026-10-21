<?php

namespace App\Clinica\Pagos\Vistas;

use App\Models\MetodoPago;
use App\Models\Plan;
use App\Models\TasaDeCambio;
use App\Pagos\Centavos;
use Illuminate\Support\Collection;

/**
 * La pantalla **«Mi plan»** del médico: qué servicio tiene, hasta cuándo, y el catálogo para renovarlo.
 *
 * No decide nada: el estado sale de `ServicioService` (el mismo que bloquea el sync), los planes del
 * catálogo visible y los métodos de la plataforma que están activos y configurados.
 */
final class PlanVista
{
    /**
     * @param  array<string,mixed>  $estado  el de `ServicioService::estado()`
     * @param  Collection<int,Plan>  $planes
     * @param  Collection<int,MetodoPago>  $metodos
     */
    public function __construct(
        public readonly string $regMedico,
        public readonly array $estado,
        public readonly Collection $planes,
        public readonly Collection $metodos,
        public readonly ?TasaDeCambio $tasa,
    ) {
    }

    public function titulo(): string
    {
        return 'Mi plan';
    }

    public function subtitulo(): string
    {
        return 'Tu suscripción a Doctorisimo: qué plan tenés, hasta cuándo y cómo renovarlo.';
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

    /** Sin tasa, sin métodos o sin planes no se puede cobrar: la pantalla lo dice en vez de ofrecer un botón muerto. */
    public function puedePagar(): bool
    {
        return $this->tasa !== null && $this->metodos->isNotEmpty() && $this->planes->isNotEmpty();
    }

    public function aviso(): ?string
    {
        if ($this->planes->isEmpty()) {
            return 'Todavía no hay planes disponibles para contratar.';
        }
        if ($this->metodos->isEmpty()) {
            return 'El administrador todavía no habilitó un método de pago para cobrar los planes.';
        }
        if ($this->tasa === null) {
            return 'El administrador todavía no cargó la tasa VES/USD, así que no se puede cobrar en bolívares.';
        }

        return null;
    }

    /** El precio del plan en VES, en centavos; `null` si todavía no hay tasa. */
    public function precioVes(Plan $plan): ?int
    {
        return $this->tasa?->convertirUsdAVes(Centavos::deDecimal((string) $plan->precio_usd));
    }

    public function precioUsdTexto(Plan $plan): string
    {
        return Centavos::formatear(Centavos::deDecimal((string) $plan->precio_usd), 'USD');
    }

    public function precioVesTexto(Plan $plan): string
    {
        $ves = $this->precioVes($plan);

        return $ves === null ? '—' : Centavos::formatear($ves, 'VES');
    }

    public function tasaTexto(): ?string
    {
        return $this->tasa?->aTexto();
    }
}
