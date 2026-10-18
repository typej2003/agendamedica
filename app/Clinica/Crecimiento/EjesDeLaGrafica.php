<?php

namespace App\Clinica\Crecimiento;

/**
 * Las marcas «redondas» de un eje (WEB-7.4.3c): dado el mínimo y el máximo de los datos devuelve un rango que los contiene y las marcas
 * cada 1, 2 o 5 (por una potencia de diez), unas seis. Pura.
 */
final class EjesDeLaGrafica
{
    /**
     * @return array{min:float, max:float, marcas:array<int,float>}
     */
    public static function marcas(float $minimo, float $maximo, int $objetivo = 6): array
    {
        if ($maximo < $minimo) {
            [$minimo, $maximo] = [$maximo, $minimo];
        }

        if ($maximo - $minimo < 1e-9) {
            // Un solo valor: se abre un rango a su alrededor para que el punto no quede pegado al borde.
            $holgura = max(abs($minimo) * 0.1, 1.0);
            $minimo -= $holgura;
            $maximo += $holgura;
        }

        $paso = self::paso(($maximo - $minimo) / max(1, $objetivo));
        $desde = floor($minimo / $paso + 1e-9) * $paso;
        $hasta = ceil($maximo / $paso - 1e-9) * $paso;

        $marcas = [];
        for ($valor = $desde; $valor <= $hasta + $paso / 2; $valor += $paso) {
            $marcas[] = round($valor, 6);
        }

        return ['min' => round($desde, 6), 'max' => round($hasta, 6), 'marcas' => $marcas];
    }

    /** El paso «redondo» (1, 2 o 5 por una potencia de diez) más cercano por arriba al paso pedido. */
    private static function paso(float $crudo): float
    {
        $potencia = 10 ** floor(log10($crudo));

        foreach ([1, 2, 5, 10] as $multiplo) {
            if ($multiplo * $potencia >= $crudo - 1e-12) {
                return $multiplo * $potencia;
            }
        }

        return 10 * $potencia;
    }
}
