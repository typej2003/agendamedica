<?php

namespace App\Pagos\Conciliacion;

/**
 * **Lo que contestó el banco** sobre una transacción declarada.
 *
 * No es el resultado del cobro: es sólo la lectura de la consulta. Quien decide si el pago se aprueba es
 * `App\Pagos\CobrosDePagoMovil`, que además compara el monto con el esperado.
 */
final class ResultadoDeConsulta
{
    public const APROBADO = 'aprobado';
    public const RECHAZADO = 'rechazado';
    public const NO_ENCONTRADO = 'no_encontrado';
    public const ERROR = 'error';

    /**
     * @param  array<string,mixed>  $respuesta  la respuesta del banco, ya saneada (sin credenciales)
     */
    public function __construct(
        public readonly string $estado,
        /** Lo que el banco dice que se pagó, en centavos. */
        public readonly ?int $montoE2 = null,
        public readonly ?string $referencia = null,
        public readonly ?string $mensaje = null,
        public readonly array $respuesta = [],
    ) {
    }

    public static function aprobado(int $montoE2, ?string $referencia = null, array $respuesta = []): self
    {
        return new self(self::APROBADO, $montoE2, $referencia, null, $respuesta);
    }

    public static function rechazado(?string $mensaje = null, array $respuesta = []): self
    {
        return new self(self::RECHAZADO, null, null, $mensaje, $respuesta);
    }

    public static function noEncontrado(?string $mensaje = null, array $respuesta = []): self
    {
        return new self(self::NO_ENCONTRADO, null, null, $mensaje, $respuesta);
    }

    public static function error(string $mensaje, array $respuesta = []): self
    {
        return new self(self::ERROR, null, null, $mensaje, $respuesta);
    }

    public function fueAprobado(): bool
    {
        return $this->estado === self::APROBADO;
    }

    public function esError(): bool
    {
        return $this->estado === self::ERROR;
    }

    /** El estado que se guarda en `pago_transacciones.estado`. */
    public function estadoDeLaTransaccion(): string
    {
        return match ($this->estado) {
            self::APROBADO => 'aprobada',
            self::RECHAZADO => 'rechazada',
            self::NO_ENCONTRADO => 'no_encontrada',
            default => 'pendiente',
        };
    }
}
