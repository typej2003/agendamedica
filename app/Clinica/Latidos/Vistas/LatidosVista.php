<?php

namespace App\Clinica\Latidos\Vistas;

use App\Latidos\Conversion;
use App\Models\Latido;
use App\Models\MovimientoDeLatidos;

/**
 * La pantalla **«Mis Latidos»**: cuántos hay, cuántos están retenidos y los últimos movimientos.
 *
 * No habla de “saldo” ni de “USD”: los Latidos son una unidad de descuento. El valor en dólares se muestra
 * sólo como equivalencia del descuento, nunca como un saldo en dinero.
 */
final class LatidosVista
{
    public function __construct(
        public readonly string $regMedico,
        public readonly ?Latido $latido,
        public readonly int $disponibles,
        public readonly int $disponiblesParaUsar,
        public readonly int $retenido,
    ) {
    }

    public function titulo(): string
    {
        return 'Mis Latidos';
    }

    public function subtitulo(): string
    {
        return 'Tus Latidos se usan como descuento en los planes. No se retiran ni se transfieren.';
    }

    public function disponiblesTexto(): string
    {
        return Conversion::formatear($this->disponibles);
    }

    public function paraUsarTexto(): string
    {
        return Conversion::formatear($this->disponiblesParaUsar);
    }

    public function retenidoTexto(): string
    {
        return Conversion::formatear($this->retenido);
    }

    public function movimientos()
    {
        return $this->latido?->movimientos ?? collect();
    }

    public function tipoLegible(string $tipo): string
    {
        return match ($tipo) {
            MovimientoDeLatidos::COMPRA => 'Compra',
            MovimientoDeLatidos::REFERIDO => 'Referido',
            MovimientoDeLatidos::PROMOCION => 'Promoción',
            MovimientoDeLatidos::DESCUENTO_PLAN => 'Descuento en el plan',
            MovimientoDeLatidos::AJUSTE => 'Ajuste',
            MovimientoDeLatidos::REVERSO => 'Reverso',
            default => ucfirst($tipo),
        };
    }
}
