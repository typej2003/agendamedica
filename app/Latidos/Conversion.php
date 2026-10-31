<?php

namespace App\Latidos;

use InvalidArgumentException;

/**
 * **La conversión de Latidos**, en un solo lugar.
 *
 * La regla del programa es **100 Latidos = USD 1 de descuento** (decisión del 2026-10-30). Como el precio
 * de los planes está en centavos de USD, `desdeUsdE2()` devuelve los Latidos necesarios para cubrirlo y
 * `aUsdE2()` el valor de descuento de un puñado de Latidos.
 *
 * Los Latidos son enteros: no hay fracciones ni `float`. Cambiar `POR_DOLAR` es un cambio de programa,
 * no un ajuste de pantalla: los movimientos históricos ya guardaron su valor en `descuento_usd_e2`.
 */
final class Conversion
{
    /** Cuántos Latidos equivalen a USD 1 de descuento. */
    public const POR_DOLAR = 100;

    /** Los Latidos necesarios para cubrir un monto en centavos de USD (redondea hacia arriba). */
    public static function desdeUsdE2(int $usdE2): int
    {
        if ($usdE2 < 0) {
            throw new InvalidArgumentException('El monto en USD no puede ser negativo.');
        }

        return intdiv($usdE2 * self::POR_DOLAR + 99, 100);
    }

    /** El valor en centavos de USD de un monto de Latidos (redondea hacia abajo). */
    public static function aUsdE2(int $latidos): int
    {
        if ($latidos < 0) {
            throw new InvalidArgumentException('Los Latidos no pueden ser negativos.');
        }

        return intdiv($latidos * 100, self::POR_DOLAR);
    }

    /** «2.000 Latidos», para una pantalla. */
    public static function formatear(int $latidos): string
    {
        return number_format($latidos, 0, ',', '.') . ' Latidos';
    }
}
