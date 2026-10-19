<?php

namespace App\Clinica\Consulta;

/**
 * **La dosis pediátrica por peso** (2026-10-19): la fórmula del escritorio, copiada de
 * `pw9_ws/w_agregar_nueva_gesta_medicamento.srw:223-254` (la ventana con la que se agrega un medicamento al récipe
 * desde el vademécum).
 *
 * El escritorio hace esto, en este orden:
 *
 *  1. toma el **peso del examen físico de la consulta** (`em_pes`) y **lo topea en 30 kg**: un niño de más de 30 kg
 *     recibe la dosis de adulto (`:223-228`);
 *  2. si el medicamento está marcado **por kilo** (`vademecum.dosificacion = 'SI'`) y `cada` (las horas entre tomas)
 *     no es cero, calcula la **posología en mg por toma**: `POSOLOGIA = (DOSIS * peso) / CADA` (`:236`);
 *  3. si el medicamento trae **presentación** (`pvc` = mg del envase, `pvs` = ml del envase), pasa los mg a **ml**:
 *     `ml = (pvs * posologia) / pvc`; y si trae **concentración** (`gotas` por ml), a **gotas**: `ml * gotas`
 *     (`:238-247`);
 *  4. arma el texto de las indicaciones con una de estas tres formas (`:242`, `:246` y `:249`):
 *     - `DAR <gotas> GOTAS CADA <horas> HORAS POR <días> DÍAS`
 *     - `DAR <ml> ML CADA <horas> HORAS POR <días> DÍAS`
 *     - `DAR <mg> mg <cada> veces al día por <días> días` (sin presentación)
 *  5. si el medicamento **no** es por kilo, deja el texto del vademécum (`uso`) tal cual (`:252-253`).
 *
 * ⚠️ **Una rareza del escritorio que la web corrige**: el `hot` del fuente es `integer`, así que **trunca** las horas
 * (`24 / 5` = 4.8 se imprime «CADA 4 HORAS»). Acá se imprime el **intervalo real** con un decimal (4.8), que es lo que
 * la fórmula significa: `vademecum.cada` son las **veces al día** (el propio fuente escribe «`cada` veces al día» en la
 * rama sin presentación) y `24 / cada` son las horas entre tomas.
 */
final class DosisPediatrica
{
    /** El tope del escritorio: por encima de 30 kg se usa la dosis de adulto (`:224-228`). */
    public const PESO_MAXIMO = 30.0;

    /** El valor de `vademecum.dosificacion` que dice «la dosis es por kilo». */
    public const POR_KILO = 'SI';

    /** Las horas del día: la cuenta del escritorio (`:231`). */
    public const HORAS_DEL_DIA = 24;

    /**
     * El peso que entra en la fórmula: el del examen físico, tope 30 kg.
     *
     * @param  mixed  $peso  lo que tenga la consulta (`consultas.peso`)
     */
    public static function pesoParaLaDosis($peso): ?float
    {
        if ($peso === null || $peso === '' || ! is_numeric($peso)) {
            return null;
        }

        $numero = (float) $peso;

        if ($numero <= 0) {
            return null;
        }

        return $numero <= self::PESO_MAXIMO ? $numero : self::PESO_MAXIMO;
    }

    /**
     * El cálculo de la dosis, o `null` si el medicamento no es por kilo o faltan los datos.
     *
     * @param  object|array<string,mixed>  $medicamento  con `dosificacion`, `dosis`, `cada`, `durante`, `pvc`, `pvs`,
     *                                                   `concentracion` y `uso` (las columnas del `vademecum`)
     * @return array{peso:float, posologia:float, cadaHoras:float, ml:?float, gotas:?float, texto:string}|null
     */
    public function calcular($medicamento, $peso): ?array
    {
        $datos = (array) $medicamento;
        $pesoDeLaDosis = self::pesoParaLaDosis($peso);

        if ($pesoDeLaDosis === null) {
            return null;
        }

        if (strtoupper(trim((string) ($datos['dosificacion'] ?? ''))) !== self::POR_KILO) {
            return null;
        }

        $dosis = $this->numero($datos['dosis'] ?? null);
        $cada = $this->numero($datos['cada'] ?? null);
        $durante = $this->numero($datos['durante'] ?? null);

        if ($dosis === null || $dosis <= 0 || $cada === null || $cada <= 0) {
            return null;
        }

        // `POSOLOGIA = DEC((DOSIS * peso)/CADA)` (`:236`), en miligramos por toma.
        $posologia = $this->redondear(($dosis * $pesoDeLaDosis) / $cada);

        // Las horas entre tomas: `24 / cada`. El escritorio las trunca a entero (`integer hot`, `:229-232`) y la web
        // imprime el intervalo real con un decimal: `cada` son las veces al día, no las horas.
        $cadaHoras = round(self::HORAS_DEL_DIA / $cada, 1);

        $gramos = $this->numero($datos['pvc'] ?? null);
        $litros = $this->numero($datos['pvs'] ?? null);
        $gotasPorMl = $this->numero($datos['concentracion'] ?? null);

        $ml = null;
        $gotas = null;
        $texto = '';

        if ($gramos !== null && $gramos != 0.0) {
            // `RES_POSO = (M_LITROS * POSOLOGIA)/M_GRAMOS` (`:240` y `:244`).
            $ml = $this->redondear((($litros ?? 0.0) * $posologia) / $gramos);

            if ($gotasPorMl !== null && $gotasPorMl != 0.0) {
                $gotas = $this->redondear($ml * $gotasPorMl);
                $texto = sprintf('DAR %s GOTAS CADA %s HORAS POR %s DÍAS', $this->texto($gotas), $this->texto($cadaHoras), $this->texto($durante));
            } else {
                $texto = sprintf('DAR %s ML CADA %s HORAS POR %s DÍAS', $this->texto($ml), $this->texto($cadaHoras), $this->texto($durante));
            }
        } else {
            $texto = sprintf('DAR %s mg %s veces al día por %s días', $this->texto($posologia), $this->texto($cada), $this->texto($durante));
        }

        return [
            'peso'      => $pesoDeLaDosis,
            'posologia' => $posologia,
            'cadaHoras' => $cadaHoras,
            'ml'        => $ml,
            'gotas'     => $gotas,
            'texto'     => $texto,
        ];
    }

    /** Un número de la columna, o `null`. */
    private function numero($valor): ?float
    {
        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            return null;
        }

        return (float) $valor;
    }

    /** Dos decimales, como el `DEC` del escritorio. */
    private function redondear(float $valor): float
    {
        return round($valor, 2);
    }

    /** El número como texto, sin ceros de más (`30`, `1.5`), como el `STRING` del escritorio. */
    private function texto(?float $valor): string
    {
        if ($valor === null) {
            return '0';
        }

        return fmod($valor, 1.0) === 0.0
            ? (string) (int) $valor
            : rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }
}
