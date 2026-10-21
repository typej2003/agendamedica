<?php

namespace App\Clinica\Pagos\Vistas;

use App\Models\MetodoPago;
use App\Models\Plan;
use App\Models\TasaDeCambio;
use App\Pagos\Centavos;
use Illuminate\Support\Collection;

/**
 * La pantalla de **confirmar el plan y elegir cómo pagarlo**: el plan ya está elegido y acá se muestra el
 * monto en la moneda de cada método (VES con la tasa del momento, o USD si el método cobra en dólares).
 *
 * La tasa no se muestra como número: el médico ve lo que va a pagar. Los métodos salen de la plataforma y
 * sólo se listan los que están activos y configurados.
 */
final class PlanElegidoVista
{
    /**
     * @param  Collection<int,MetodoPago>  $metodos
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly Collection $metodos,
        public readonly ?TasaDeCambio $tasa,
    ) {
    }

    public function titulo(): string
    {
        return 'Pagar el plan';
    }

    public function subtitulo(): string
    {
        return 'Revisa el plan y elige cómo pagar. El monto se muestra en la moneda del método.';
    }

    public function precioUsdTexto(): string
    {
        return Centavos::formatear($this->precioUsdE2(), 'USD');
    }

    /** El monto que se cobra con ese método, en su moneda; `null` si falta la tasa o la moneda no se maneja. */
    public function montoDelMetodo(MetodoPago $metodo): ?array
    {
        $moneda = $this->monedaDelMetodo($metodo);
        $usd = $this->precioUsdE2();

        if ($moneda === 'VES') {
            if ($this->tasa === null) {
                return null;
            }
            $centavos = $this->tasa->convertirUsdAVes($usd);
        } elseif ($moneda === 'USD') {
            $centavos = $usd;
        } else {
            return null;
        }

        return [
            'centavos' => $centavos,
            'moneda' => $moneda,
            'texto' => Centavos::formatear($centavos, $moneda),
        ];
    }

    public function montoTexto(MetodoPago $metodo): string
    {
        $monto = $this->montoDelMetodo($metodo);

        return $monto === null ? 'Monto no disponible' : $monto['texto'];
    }

    public function monedaDelMetodo(MetodoPago $metodo): string
    {
        return strtoupper((string) ($metodo->cuentaBancaria?->moneda ?: 'VES'));
    }

    public function aviso(): ?string
    {
        if ($this->metodos->isEmpty()) {
            return 'No hay un método de pago disponible para cobrar este plan.';
        }

        foreach ($this->metodos as $metodo) {
            if ($this->montoDelMetodo($metodo) === null) {
                return 'Falta la tasa VES/USD para mostrar el monto en bolívares. El administrador tiene que cargarla.';
            }
        }

        return null;
    }

    public function puedeContinuar(): bool
    {
        return $this->metodos->isNotEmpty() && $this->aviso() === null;
    }

    public function frecuencia(): string
    {
        return $this->plan->frecuencia === Plan::ANUAL ? 'Anual (12 meses)' : 'Mensual';
    }

    private function precioUsdE2(): int
    {
        return Centavos::deDecimal((string) $this->plan->precio_usd);
    }
}
