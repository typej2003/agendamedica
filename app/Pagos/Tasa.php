<?php

namespace App\Pagos;

use InvalidArgumentException;

/**
 * La **tasa VES/USD** es un factor, no un monto: se guarda en `DECIMAL(18,6)` y se muestra como
 * `"36,5000"`. Para que la cuenta no pase por `float`, se lleva a un entero escalado (×1.000.000) y se
 * opera con enteros, igual que `Centavos`.
 *
 * `usdAVes()` y `vesAUsd()` redondean half-up al centavo de la moneda destino. El producto máximo está
 * acotado por `config('pagos.maximo_monto_usd_e2')` y `config('pagos.maximo_tasa')` para no desbordar
 * un entero de 64 bits (ver el docblock de la migración).
 */
final class Tasa
{
    /** Decimales con los que se guarda y se opera la tasa. */
    public const ESCALA = 6;

    private const FACTOR = 1_000_000;

    /** `"36,5000"` → `36500000`. */
    public static function aEscala(string|int|float|null $tasa): int
    {
        return Centavos::deEscala($tasa, self::ESCALA);
    }

    /** `36500000` → `"36.500000"`. */
    public static function desdeEscala(int $escala): string
    {
        return intdiv($escala, self::FACTOR) . '.' . str_pad((string) ($escala % self::FACTOR), self::ESCALA, '0', STR_PAD_LEFT);
    }

    /** Cuántos centavos de VES equivalen a `$usdE2` centavos de USD. */
    public static function usdAVes(int $usdE2, string|int|float|null $tasa): int
    {
        $escala = self::aEscala($tasa);
        self::verificar($escala);

        return intdiv($usdE2 * $escala + intdiv(self::FACTOR, 2), self::FACTOR);
    }

    /** Cuántos centavos de USD equivalen a `$vesE2` centavos de VES. */
    public static function vesAUsd(int $vesE2, string|int|float|null $tasa): int
    {
        $escala = self::aEscala($tasa);
        self::verificar($escala);

        return intdiv($vesE2 * self::FACTOR + intdiv($escala, 2), $escala);
    }

    private static function verificar(int $escala): void
    {
        if ($escala <= 0) {
            throw new InvalidArgumentException('La tasa VES/USD tiene que ser mayor que cero.');
        }
    }
}
