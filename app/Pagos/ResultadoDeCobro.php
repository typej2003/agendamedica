<?php

namespace App\Pagos;

use App\Models\Pago;

/**
 * Lo que devuelve una operación de cobro (iniciar, confirmar, conciliar) al controlador o al comando: si
 * salió bien, el mensaje para mostrar y el pago con su estado nuevo.
 */
final class ResultadoDeCobro
{
    /**
     * @param  array<string,mixed>  $respuesta
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $mensaje,
        public readonly ?Pago $pago = null,
        public readonly array $respuesta = [],
        // ¿El pago cambió de estado en esta operación? Lo usa el motor para mandar el comprobante una
        // sola vez, incluso si el webhook y el sondeo llegan juntos.
        public readonly bool $cambio = false,
    ) {
    }

    /** @param array<string,mixed> $respuesta */
    public static function ok(string $mensaje, ?Pago $pago = null, array $respuesta = [], bool $cambio = false): self
    {
        return new self(true, $mensaje, $pago, $respuesta, $cambio);
    }

    /** @param array<string,mixed> $respuesta */
    public static function error(string $mensaje, ?Pago $pago = null, array $respuesta = []): self
    {
        return new self(false, $mensaje, $pago, $respuesta);
    }

    public function estado(): ?string
    {
        return $this->pago?->estado;
    }
}
