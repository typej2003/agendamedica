<?php

namespace App\Pagos\Conciliacion;

use Carbon\CarbonInterface;

/**
 * **Lo que se le pregunta al banco**: si existe una transacción con esa referencia, de ese pagador y por
 * ese monto.
 *
 * La referencia la genera el banco del pagador, así que el dato lo tiene que dar el paciente (o el
 * consultorio, mirando su banco). No hay forma de «buscar lo que entró»: la API consulta una transacción
 * concreta.
 */
final class ConsultaDePago
{
    public function __construct(
        /** `PM` (pago móvil) o `TRF` (transferencia). */
        public readonly string $tipo,
        public readonly string $referencia,
        /** En centavos de la moneda de la cuenta (VES). */
        public readonly int $montoE2,
        /** «V12345678»: tipo de documento + número, sin guiones. */
        public readonly string $documento,
        /** El teléfono del pagador; vacío en una transferencia. */
        public readonly string $telefono = '',
        public readonly ?CarbonInterface $fecha = null,
    ) {
    }

    public function esTransferencia(): bool
    {
        return $this->tipo === 'TRF';
    }

    public function monto(): string
    {
        return number_format($this->montoE2 / 100, 2, '.', '');
    }
}
