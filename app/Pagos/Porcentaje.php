<?php

namespace App\Pagos;

use InvalidArgumentException;

/**
 * Porcentajes en **puntos base** (`_bp`): `10000` = 100%, `1000` = 10%, `1250` = 12,5%. La cuenta se
 * hace con enteros y redondeo half-up, sin `float`.
 */
final class Porcentaje
{
    public const TOTAL = 10_000;

    /** Cuánto es `$bp` por ciento de `$baseE2` (redondeado al centavo). */
    public static function aplicar(int $baseE2, int $bp): int
    {
        if ($bp < 0 || $bp > self::TOTAL) {
            throw new InvalidArgumentException('El porcentaje tiene que estar entre 0 y 10000 puntos base.');
        }
        if ($baseE2 < 0) {
            throw new InvalidArgumentException('La base no puede ser negativa.');
        }

        return intdiv($baseE2 * $bp + intdiv(self::TOTAL, 2), self::TOTAL);
    }

    /** `1000` → `"10,00%"`. */
    public static function aTexto(int $bp): string
    {
        return number_format($bp / 100, 2, ',', '.') . '%';
    }
}
