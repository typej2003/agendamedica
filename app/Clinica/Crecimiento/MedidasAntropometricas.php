<?php

namespace App\Clinica\Crecimiento;

/**
 * **Las fórmulas del IMC y del «AC»** de la pestaña *Mediciones* del escritorio de pediatría (WEB-7.4.3a), copiadas de sus funciones
 * globales `gffimc.srf` y `gffac.srf` (`Docs/MICONSULTAGI-legado/fuentes-pediatria/utilitarios/`). El escritorio las llama con el peso en
 * kg y la talla **en metros** (`gffIMC(peso, talla/100)`), y muestra el resultado con 2 decimales (`decimal {2}`).
 *
 *  - **IMC** = peso / talla². Con peso o talla ≤ 0 devuelve 0.
 *  - **AC** (la «superficie corporal» del escritorio): `talla_cm^0,725 · peso^0,425 · 71,84 / 10000`. Si da menos de 0,6 —un recién
 *    nacido— usa la variante en gramos: `talla_cm^0,3 · peso_g^(0,7285 − 0,0188·ln peso_g) · 3,207 / 10000`.
 *
 * Es pura (sin base de datos). Recibe la talla en **centímetros**, que es como se guarda en `percentiles_paciente.talla`.
 */
final class MedidasAntropometricas
{
    public static function imc(?float $pesoKg, ?float $tallaCm): float
    {
        $metros = ($tallaCm ?? 0) / 100;

        if ($metros <= 0 || ($pesoKg ?? 0) <= 0) {
            return 0.0;
        }

        return round($pesoKg / ($metros ** 2), 2);
    }

    public static function ac(?float $pesoKg, ?float $tallaCm): float
    {
        if (($tallaCm ?? 0) <= 0 || ($pesoKg ?? 0) <= 0) {
            return 0.0;
        }

        $ac = ($tallaCm ** 0.725) * ($pesoKg ** 0.425) * 71.84 / 10000;

        if ($ac < 0.6) {
            $gramos = $pesoKg * 1000;
            $ac = ($tallaCm ** 0.3) * ($gramos ** (0.7285 - (0.0188 * log($gramos)))) * 3.207 / 10000;
        }

        return round($ac, 2);
    }
}
