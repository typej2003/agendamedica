<?php

namespace App\Pagos;

use InvalidArgumentException;

/**
 * **El dinero, en centavos enteros** (decisión del usuario, 2026-10-21): nada de `float` en una cuenta
 * de dinero. Las columnas de la base llevan el sufijo `_e2` y guardan `BIGINT`; acá se convierte desde y
 * hacia el texto decimal que muestran los formularios.
 *
 * `deDecimal()` acepta `19.99`, `19,99` y `1.234,56` (el formato venezolano) y **redondea** al centavo
 * (half-up) en vez de truncar. Nunca pasa por coma flotante: se separa la parte entera de la decimal y se
 * arma el entero.
 *
 * `deEscala()` es la misma idea para un factor (la tasa VES/USD), con los decimales que se le pidan.
 */
final class Centavos
{
    /** Cuántos decimales tiene un monto en centavos. */
    public const DECIMALES = 2;

    /** Convierte un monto decimal a centavos. */
    public static function deDecimal(string|int|float|null $valor): int
    {
        return self::deEscala($valor, self::DECIMALES);
    }

    /**
     * Convierte un decimal a un entero escalado: `deEscala('36,1234', 6)` = `36123400`.
     *
     * Es el puente entre la columna `DECIMAL(18,6)` de la tasa (que es un factor, no un monto) y la
     * cuenta entera que hace `Tasa`. Redondea half-up con el primer dígito que sobra.
     */
    public static function deEscala(string|int|float|null $valor, int $decimales): int
    {
        if ($decimales < 0) {
            throw new InvalidArgumentException('Los decimales no pueden ser negativos.');
        }
        if ($valor === null || $valor === '') {
            return 0;
        }
        if (is_int($valor)) {
            return $valor * (10 ** $decimales);
        }
        if (is_float($valor)) {
            // Sólo en el borde: se pasa a texto con los decimales justos antes de separar.
            $valor = number_format($valor, $decimales, '.', '');
        }

        $texto = trim((string) $valor);
        $negativo = str_starts_with($texto, '-');
        $texto = ltrim($texto, '+-');

        // Formato venezolano "1.234,56": la coma es el decimal y el punto el separador de miles.
        if (str_contains($texto, ',')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        [$entero, $decimal] = array_pad(explode('.', $texto, 2), 2, '');

        $entero = preg_replace('/\D/', '', $entero);
        $decimal = preg_replace('/\D/', '', $decimal);

        $entero = $entero === '' ? '0' : $entero;
        $decimal = $decimal === '' ? '0' : $decimal;

        $escala = 10 ** $decimales;
        $recortado = substr(str_pad($decimal, $decimales, '0'), 0, $decimales);
        $siguiente = (int) substr(str_pad($decimal, $decimales + 1, '0'), $decimales, 1);

        $valorEntero = ((int) $entero) * $escala + (int) $recortado;
        if ($siguiente >= 5) {
            $valorEntero++;
        }

        return $negativo ? -$valorEntero : $valorEntero;
    }

    /** `1999` → `"19.99"` (siempre con los dos decimales, sin separador de miles). */
    public static function aDecimal(int $centavos): string
    {
        $negativo = $centavos < 0;
        $absoluto = abs($centavos);

        $texto = intdiv($absoluto, 100) . '.' . str_pad((string) ($absoluto % 100), 2, '0', STR_PAD_LEFT);

        return ($negativo ? '-' : '') . $texto;
    }

    /** `1999` → `"19,99"` con separador de miles, para una pantalla. */
    public static function aTexto(int $centavos): string
    {
        return number_format($centavos / 100, 2, ',', '.');
    }

    /** `1999, 'USD'` → `"USD 19,99"`. */
    public static function formatear(int $centavos, string $moneda = 'USD'): string
    {
        return trim($moneda . ' ' . self::aTexto($centavos));
    }
}
