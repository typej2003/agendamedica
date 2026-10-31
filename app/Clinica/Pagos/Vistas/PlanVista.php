<?php

namespace App\Clinica\Pagos\Vistas;

use App\Models\Plan;
use App\Pagos\Centavos;
use Illuminate\Support\Collection;

/**
 * La pantalla **«Mi plan»** del médico: qué servicio tiene, hasta cuándo, el equipo actual y el catálogo
 * para renovarlo.
 *
 * No decide nada: el estado sale de `ServicioService` y los precios/cupos de `CupoDelPlan` (los recibe ya
 * calculados). **No muestra la tasa ni los métodos de pago**: eso se elige en la pantalla siguiente.
 */
final class PlanVista
{
    /**
     * @param  array<string,mixed>  $estado  el de `ServicioService::estado()`
     * @param  Collection<int,Plan>  $planes
     * @param  array{medicos:int,secretarias:int}  $conteo
     * @param  array<int,int>  $preciosE2  plan id => precio en centavos para el equipo actual
     * @param  array<int,?string>  $detalles  plan id => desglose mensual por asiento (null si es fijo)
     * @param  array<int,?string>  $motivos  plan id => por qué no cubre al equipo (null si lo cubre o es el actual)
     * @param  array<int,int>  $preciosAnualesE2  plan id => precio anual para el equipo actual
     * @param  array<int,int>  $ahorrosAnualesE2  plan id => ahorro del anual frente a 12 meses
     * @param  array<int,?string>  $detallesAnuales  plan id => desglose del anual
     * @param  string  $frecuenciaElegida  la del selector global (anual por defecto)
     */
    public function __construct(
        public readonly string $regMedico,
        public readonly array $estado,
        public readonly Collection $planes,
        public readonly array $conteo = ['medicos' => 0, 'secretarias' => 0],
        public readonly array $preciosE2 = [],
        public readonly array $detalles = [],
        public readonly array $motivos = [],
        public readonly array $preciosAnualesE2 = [],
        public readonly array $ahorrosAnualesE2 = [],
        public readonly array $detallesAnuales = [],
        public readonly string $frecuenciaElegida = Plan::ANUAL,
        public readonly int $latidosDisponibles = 0,
        public readonly array $renovacion = [],
    ) {
    }

    public function renovacionAutomatica(): bool
    {
        return (bool) ($this->renovacion['activa'] ?? false);
    }

    public function renovacionCubierta(): bool
    {
        return (bool) ($this->renovacion['cubierta'] ?? false);
    }

    public function avisoPorVencer(): bool
    {
        return (bool) ($this->renovacion['aviso_por_vencer'] ?? true);
    }

    /** “Se renueva solo el 30/11/2026 con 2.000 Latidos.” Null si no está cubierta. */
    public function renovacionTexto(): ?string
    {
        if (! $this->renovacionCubierta() || empty($this->renovacion['vence_el'])) {
            return null;
        }

        $fecha = \Carbon\Carbon::parse((string) $this->renovacion['vence_el'])
            ->format(\App\Support\Regionalizacion::formatoActual());

        return 'Se renueva solo el ' . $fecha . ' con '
            . \App\Latidos\Conversion::formatear((int) ($this->renovacion['latidos_necesarios'] ?? 0));
    }

    public function latidosDisponibles(): int
    {
        return $this->latidosDisponibles;
    }

    public function latidosDisponiblesTexto(): string
    {
        return \App\Latidos\Conversion::formatear($this->latidosDisponibles);
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

    public function medicos(): int
    {
        return (int) ($this->conteo['medicos'] ?? 0);
    }

    public function secretarias(): int
    {
        return (int) ($this->conteo['secretarias'] ?? 0);
    }

    /** “Tu equipo: 2 médicos y 1 secretaría.” */
    public function uso(): string
    {
        return 'Tu equipo: ' . $this->medicos() . ' médico' . ($this->medicos() === 1 ? '' : 's')
            . ' y ' . $this->secretarias() . ' secretaría' . ($this->secretarias() === 1 ? '' : 's') . '.';
    }

    /** ¿El plan del catálogo es el que ya está contratado? */
    public function esElPlanActual(Plan $plan): bool
    {
        $actual = $this->estado['plan_id'] ?? null;

        if ($actual !== null) {
            return (int) $actual === (int) $plan->id;
        }

        return trim((string) ($this->estado['plan'] ?? '')) === trim((string) $plan->nombre);
    }

    public function precioUsdTexto(Plan $plan): string
    {
        $centavos = $this->preciosE2[$plan->id] ?? Centavos::deDecimal((string) $plan->precio_usd);

        return Centavos::formatear($centavos, 'USD');
    }

    /** El “desde” del plan por asiento, para la fila de precio. */
    public function precioDesdeTexto(Plan $plan): string
    {
        return $this->precioUsdTexto($plan);
    }

    /** El precio anterior, el que se muestra tachado cuando el plan tiene descuento. */
    public function precioTachadoUsdTexto(Plan $plan): string
    {
        return Centavos::formatear(Centavos::deDecimal((string) $plan->precio_tachado_usd), 'USD');
    }

    public function ofreceAnual(Plan $plan): bool
    {
        return $plan->ofreceAnual();
    }

    public function esAnual(): bool
    {
        return $this->frecuenciaElegida === Plan::ANUAL;
    }

    public function urlFrecuencia(string $frecuencia): string
    {
        return route('clinica.plan', ['frecuencia' => $frecuencia]);
    }

    /**
     * La frecuencia con la que se muestra esa fila: la global si el plan la ofrece; si no, la otra.
     * Así un plan sólo mensual sigue mostrando un precio aunque el selector esté en anual.
     */
    public function frecuenciaDeLaFila(Plan $plan): string
    {
        if ($this->frecuenciaElegida === Plan::ANUAL && $plan->ofreceAnual()) {
            return Plan::ANUAL;
        }
        if ($this->frecuenciaElegida === Plan::MENSUAL && $plan->ofreceMensual()) {
            return Plan::MENSUAL;
        }

        return $plan->ofreceAnual() ? Plan::ANUAL : Plan::MENSUAL;
    }

    public function precioDeLaFila(Plan $plan): string
    {
        return $this->frecuenciaDeLaFila($plan) === Plan::ANUAL
            ? $this->precioAnualUsdTexto($plan)
            : $this->precioUsdTexto($plan);
    }

    public function periodoDeLaFila(Plan $plan): string
    {
        return $this->frecuenciaDeLaFila($plan) === Plan::ANUAL ? 'año' : 'mes';
    }

    public function detalleDeLaFila(Plan $plan): ?string
    {
        return $this->frecuenciaDeLaFila($plan) === Plan::ANUAL
            ? $this->detallePrecioAnual($plan)
            : $this->detallePrecio($plan);
    }

    /** El ahorro del anual, sólo cuando la fila se muestra en anual y el plan también tiene mensual. */
    public function ahorroDeLaFila(Plan $plan): ?string
    {
        if ($this->frecuenciaDeLaFila($plan) !== Plan::ANUAL || ! $plan->ofreceMensual()) {
            return null;
        }

        return $this->ahorroAnualTexto($plan);
    }

    public function mesesDeAhorroDeLaFila(Plan $plan): int
    {
        return $this->ahorroDeLaFila($plan) === null ? 0 : $this->mesesDeAhorro($plan);
    }

    public function urlComoPagar(Plan $plan): string
    {
        return route('clinica.plan.elegir', [
            $plan,
            'frecuencia' => $this->frecuenciaDeLaFila($plan),
        ]);
    }

    public function precioAnualUsdTexto(Plan $plan): string
    {
        $centavos = $this->preciosAnualesE2[$plan->id] ?? Centavos::deDecimal((string) $plan->precio_anual_usd);

        return Centavos::formatear($centavos, 'USD');
    }

    public function ahorroAnualTexto(Plan $plan): string
    {
        $centavos = $this->ahorrosAnualesE2[$plan->id] ?? Centavos::deDecimal((string) $plan->ahorroAnualUsd());

        return Centavos::formatear($centavos, 'USD');
    }

    public function detallePrecioAnual(Plan $plan): ?string
    {
        return $this->detallesAnuales[$plan->id] ?? null;
    }

    /** Cuántos meses de mensualidad representa el ahorro del anual (2 en el caso normal). */
    public function mesesDeAhorro(Plan $plan): int
    {
        $mensual = (int) ($this->preciosE2[$plan->id] ?? 0);
        if ($mensual <= 0) {
            return 0;
        }

        return max(0, (int) round(((int) ($this->ahorrosAnualesE2[$plan->id] ?? 0)) / $mensual));
    }

    public function ahorroTexto(Plan $plan): string
    {
        return Centavos::formatear(Centavos::deDecimal((string) $plan->ahorroUsd()), 'USD');
    }

    public function esPorAsiento(Plan $plan): bool
    {
        return $plan->cobraPorAsiento();
    }

    public function detallePrecio(Plan $plan): ?string
    {
        return $this->detalles[$plan->id] ?? null;
    }

    /** Por qué este plan no cubre al equipo; null si lo cubre o si es el actual (que se puede renovar). */
    public function motivoNoContratable(Plan $plan): ?string
    {
        if ($this->esElPlanActual($plan)) {
            return null;
        }

        return $this->motivos[$plan->id] ?? null;
    }

    public function puedeContratar(Plan $plan): bool
    {
        return $this->motivoNoContratable($plan) === null;
    }

    /** @return array<int,string> lo que incluye el plan, en texto corto para la tabla */
    public function incluye(Plan $plan): array
    {
        $maxMedicos = $plan->restricciones->maxMedicos();
        $maxSecretarias = $plan->restricciones->maxSecretarias();

        $items = [
            $maxMedicos === null ? 'Médicos sin límite' : $maxMedicos . ' médico' . ($maxMedicos === 1 ? '' : 's'),
            $maxSecretarias === null ? 'Secretarías sin límite' : $maxSecretarias . ' secretaría' . ($maxSecretarias === 1 ? '' : 's'),
            $plan->caracteristicas->impresionReportes()
                ? 'Impresión de reportes y PDF'
                : 'Sin impresión de reportes',
        ];

        if ($plan->cobraPorAsiento()) {
            $items[] = 'Cobro por asiento: '
                . Centavos::formatear(Centavos::deDecimal((string) $plan->caracteristicas->precioAsientoUsd()), 'USD')
                . ' por médico o secretaría';
        }

        return $items;
    }
}
