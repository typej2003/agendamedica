<?php

namespace App\Clinica\Impresion;

/**
 * **Un medicamento de un récipe tal como se imprime** (WEB-5.2): su cantidad, su indicación y los nombres que
 * puede llevar —el comercial o el genérico, más la presentación— para que quien imprime elija con cuál sale.
 *
 * El criterio es el de `Medicamento.nombre(generico:)` del móvil y el de la hoja del legado: se arma
 * **`NOMBRE / presentación`** con el nombre pedido y, si ese falta, con el otro; si el medicamento ya no está en
 * el vademécum sale lo que la fila guardó en `descripcion`, y en último caso el código. Sale **en mayúsculas**,
 * como en el legado.
 */
final class ItemImpreso
{
    public function __construct(
        public readonly string $codigo,
        public readonly ?int $cantidad,
        public readonly ?string $indicaciones,
        public readonly ?string $comercial,
        public readonly ?string $generico,
        public readonly ?string $presentacion,
        public readonly ?string $respaldo,
    ) {
    }

    /** El nombre con el que sale impreso: `NOMBRE / PRESENTACIÓN`, comercial o genérico, siempre en mayúsculas. */
    public function nombre(bool $generico = false): string
    {
        $base = $generico ? ($this->generico ?? $this->comercial) : ($this->comercial ?? $this->generico);

        if ($base === null) {
            return mb_strtoupper($this->respaldo ?? 'Medicamento ' . $this->codigo);
        }

        return mb_strtoupper($this->presentacion === null ? $base : $base . ' / ' . $this->presentacion);
    }
}
