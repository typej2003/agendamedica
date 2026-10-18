<?php

namespace App\Clinica\Crecimiento;

/**
 * **Entre qué percentiles cae** la presión arterial o el pulso (WEB-7.4.3b). Las tablas de referencia traen siete percentiles por edad
 * (3, 10, 25, 50, 75, 90 y 97: `tres`, `diez`, `veinte_5`…), así que acá sí hay números: el valor queda «menor al P3», «entre el P25 y el
 * P50», «mayor al P97»…
 */
final class PosicionEnPercentiles
{
    /**
     * @param  array<int,float>  $curvas  percentil => valor de la referencia a esa edad
     * @param  int|null  $desde  el percentil de abajo (`null` = menor al más bajo)
     * @param  int|null  $hasta  el percentil de arriba (`null` = mayor al más alto)
     */
    public function __construct(
        public readonly string $medida,
        public readonly float $valor,
        public readonly ?int $desde,
        public readonly ?int $hasta,
        public readonly array $curvas,
    ) {
    }

    public function texto(): string
    {
        if ($this->desde === null) {
            return 'Menor al P' . $this->hasta;
        }

        if ($this->hasta === null) {
            return 'Mayor al P' . $this->desde;
        }

        return 'Entre el P' . $this->desde . ' y el P' . $this->hasta;
    }
}
