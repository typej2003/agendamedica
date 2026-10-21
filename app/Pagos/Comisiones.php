<?php

namespace App\Pagos;

/**
 * **La cascada de comisiones** (decisión del usuario, 2026-10-21). No se suman sobre el bruto: la
 * comisión del método pega primero y la de cita online pega sobre lo que queda.
 *
 *   Bruto            100,00
 *   Método 10%       -10,00
 *   Base cita online  90,00
 *   Cita online 30%  -27,00
 *   Neto              63,00
 *
 * Todo en centavos y con `Porcentaje::aplicar()` (enteros). El neto es una resta, así que las partes
 * siempre suman el bruto y no queda un centavo perdido.
 */
final class Comisiones
{
    /**
     * @return array{metodo:int, base_cita_online:int, cita_online:int, total:int, neto:int}
     */
    public static function calcular(int $brutoUsdE2, int $bpMetodo = 0, int $bpCitaOnline = 0): array
    {
        if ($brutoUsdE2 < 0) {
            throw new \InvalidArgumentException('El bruto no puede ser negativo.');
        }

        $metodo = Porcentaje::aplicar($brutoUsdE2, $bpMetodo);
        $baseCitaOnline = $brutoUsdE2 - $metodo;
        $citaOnline = Porcentaje::aplicar($baseCitaOnline, $bpCitaOnline);

        return [
            'metodo' => $metodo,
            'base_cita_online' => $baseCitaOnline,
            'cita_online' => $citaOnline,
            'total' => $metodo + $citaOnline,
            'neto' => $baseCitaOnline - $citaOnline,
        ];
    }
}
