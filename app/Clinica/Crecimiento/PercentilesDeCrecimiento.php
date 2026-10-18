<?php

namespace App\Clinica\Crecimiento;

use Carbon\Carbon;

/**
 * **El servicio de percentiles de crecimiento** (WEB-7.4.3b): dada una medida, el sexo y la edad en días, dice en qué tramo de las curvas de
 * referencia cae. Es pura (sin base de datos): la referencia se la pasa quien la construye (`ReferenciaDeCrecimiento::cargar`).
 *
 * La referencia del escritorio **no es LMS / z-score**: `percentil_nino` y `percentil_nina` traen, por rango de edad en días
 * (`edad_ini_dia`–`edad_fin_dia`) y en años (`anos`, el eje de las gráficas), tres curvas (`_b` baja, `_m` media, `_a` alta) para peso, talla,
 * perímetro cefálico, perímetro del brazo e IMC. La presión (sistólica y diastólica) y el pulso traen siete percentiles por edad. Si más
 * adelante se confirma la OMS (pregunta 1), se agrega otra implementación con la misma forma sin tocar las pantallas.
 *
 * Las filas son arreglos asociativos con los nombres de columna de las tablas. Sin referencia para ese sexo, o con la edad fuera de ella,
 * las consultas devuelven `null`: nunca se inventa un lugar.
 */
final class PercentilesDeCrecimiento
{
    /** Las medidas de las tres curvas y el prefijo de sus columnas en la referencia. */
    public const MEDIDAS = [
        'peso' => 'peso',
        'talla' => 'talla',
        'cefalica' => 'cefalica',
        'brazo' => 'brazo',
        'imc' => 'imc',
    ];

    /** Los percentiles de la presión y el pulso: columna => percentil. */
    public const PERCENTILES = [
        'tres' => 3, 'diez' => 10, 'veinte_5' => 25, 'cincuenta' => 50,
        'setenta_5' => 75, 'noventa' => 90, 'noventa_7' => 97,
    ];

    /** Las tablas de presión y pulso. */
    public const SISTOLICA = 'sistolica';
    public const DIASTOLICA = 'diastolica';
    public const PULSO = 'pulso';

    /**
     * @param  array<int,array<string,mixed>>  $nino  filas de `percentil_nino`
     * @param  array<int,array<string,mixed>>  $nina  filas de `percentil_nina`
     * @param  array<string,array<int,array<string,mixed>>>  $otras  `sistolica`, `diastolica` y `pulso` (filas con `sexo`)
     */
    public function __construct(private array $nino, private array $nina, private array $otras = [])
    {
    }

    /** `M` → `nino`, `F` → `nina`; cualquier otra cosa no tiene referencia. */
    public static function sexoDeReferencia(?string $sexo): ?string
    {
        return match (strtoupper(trim((string) $sexo))) {
            'M' => 'nino',
            'F' => 'nina',
            default => null,
        };
    }

    /** La edad en días a una fecha, o `null` si falta alguna o la fecha es anterior al nacimiento. */
    public static function diasDeEdad(?string $nacimiento, ?string $fecha): ?int
    {
        if (! $nacimiento || ! $fecha) {
            return null;
        }

        try {
            $dias = Carbon::parse($nacimiento)->startOfDay()->diffInDays(Carbon::parse($fecha)->startOfDay(), false);
        } catch (\Throwable) {
            return null;
        }

        return $dias < 0 ? null : (int) $dias;
    }

    /** ¿Hay alguna fila de referencia para ese sexo? */
    public function hayReferencia(?string $sexo): bool
    {
        $clave = self::sexoDeReferencia($sexo);

        return $clave !== null && $this->filasDe($clave) !== [];
    }

    /**
     * La fila de referencia de un rango de edad. Si la edad cae en un hueco entre dos rangos se usa el anterior; pasado el último rango
     * (o antes del primero) no hay fila.
     *
     * @param  string  $sexo  `nino` o `nina`
     * @return array<string,mixed>|null
     */
    public function filaDe(string $sexo, int $dias): ?array
    {
        return self::filaPorEdad($this->filasDe($sexo), $dias);
    }

    /** La posición de una medida (peso, talla, cefalica, brazo o imc) en las tres curvas, o `null` si no se puede decir. */
    public function banda(string $medida, ?float $valor, ?string $sexo, ?int $dias): ?PosicionEnBandas
    {
        $clave = self::sexoDeReferencia($sexo);

        if ($valor === null || $valor <= 0 || $dias === null || $clave === null || ! isset(self::MEDIDAS[$medida])) {
            return null;
        }

        $fila = $this->filaDe($clave, $dias);
        $curvas = $fila === null ? null : self::curvas($fila, self::MEDIDAS[$medida]);

        if ($curvas === null) {
            return null;
        }

        $zona = match (true) {
            $valor < $curvas['b'] => PosicionEnBandas::DEBAJO,
            $valor < $curvas['m'] => PosicionEnBandas::BAJO_MEDIO,
            $valor <= $curvas['a'] => PosicionEnBandas::MEDIO_ALTO,
            default => PosicionEnBandas::ENCIMA,
        };

        // La curva más cercana; en un empate gana la de más abajo (b, m, a).
        $banda = 'b';
        $distancia = abs($valor - $curvas['b']);
        foreach (['m', 'a'] as $candidata) {
            if (abs($valor - $curvas[$candidata]) < $distancia) {
                $banda = $candidata;
                $distancia = abs($valor - $curvas[$candidata]);
            }
        }

        return new PosicionEnBandas($medida, $valor, $banda, $zona, $curvas);
    }

    /**
     * Las tres curvas de una medida, punto por punto, para dibujar las bandas: una entrada por fila de referencia que las tenga completas,
     * ordenada por edad. `desde`/`hasta` (en años) recortan el juego (0–5 o 5–19).
     *
     * @return array<int,array{anos:float, dias:int, b:float, m:float, a:float}>
     */
    public function puntosDeBandas(string $medida, ?string $sexo, ?float $desde = null, ?float $hasta = null): array
    {
        $clave = self::sexoDeReferencia($sexo);

        if ($clave === null || ! isset(self::MEDIDAS[$medida])) {
            return [];
        }

        $puntos = [];
        foreach ($this->filasDe($clave) as $fila) {
            $anos = self::anosDe($fila);
            $curvas = self::curvas($fila, self::MEDIDAS[$medida]);

            if ($curvas === null || ($desde !== null && $anos < $desde) || ($hasta !== null && $anos > $hasta)) {
                continue;
            }

            $puntos[] = ['anos' => $anos, 'dias' => (int) $fila['edad_ini_dia']] + $curvas;
        }

        usort($puntos, fn ($x, $y) => $x['anos'] <=> $y['anos']);

        return $puntos;
    }

    /**
     * Las tres curvas del peso **por talla** (la gráfica de peso/talla): cada fila de referencia aporta un punto por curva, con la talla de esa
     * curva en el eje x y el peso de esa curva en el eje y (como arma el escritorio sus `grafica_peso_talla`).
     *
     * @return array{b:array<int,array{talla:float, peso:float}>, m:array<int,array{talla:float, peso:float}>, a:array<int,array{talla:float, peso:float}>}
     */
    public function curvasDePesoPorTalla(?string $sexo, ?float $desde = null, ?float $hasta = null): array
    {
        $clave = self::sexoDeReferencia($sexo);
        $salida = ['b' => [], 'm' => [], 'a' => []];

        if ($clave === null) {
            return $salida;
        }

        foreach ($this->filasDe($clave) as $fila) {
            $anos = self::anosDe($fila);

            if (($desde !== null && $anos < $desde) || ($hasta !== null && $anos > $hasta)) {
                continue;
            }

            foreach (array_keys($salida) as $curva) {
                $peso = self::numero($fila['peso_' . $curva] ?? null);
                $talla = self::numero($fila['talla_' . $curva] ?? null);

                if ($peso !== null && $talla !== null) {
                    $salida[$curva][] = ['talla' => $talla, 'peso' => $peso];
                }
            }
        }

        foreach ($salida as $curva => $puntos) {
            usort($puntos, fn ($x, $y) => $x['talla'] <=> $y['talla']);
            $salida[$curva] = $puntos;
        }

        return $salida;
    }

    /** ¿Hay referencia de presión o pulso (`sistolica`, `diastolica`, `pulso`)? */
    public function hayReferenciaDe(string $tipo): bool
    {
        return ($this->otras[$tipo] ?? []) !== [];
    }

    /** Entre qué percentiles cae la presión sistólica, la diastólica o el pulso. */
    public function percentil(string $tipo, ?float $valor, ?string $sexo, ?int $dias): ?PosicionEnPercentiles
    {
        if ($valor === null || $valor <= 0 || $dias === null) {
            return null;
        }

        $fila = self::filaPorEdad($this->filasDeOtra($tipo, $sexo), $dias);
        $curvas = $fila === null ? null : self::percentiles($fila);

        if ($curvas === null) {
            return null;
        }

        $desde = null;
        $hasta = null;
        foreach ($curvas as $percentil => $referencia) {
            if ($valor >= $referencia) {
                $desde = $percentil;
            } else {
                $hasta = $percentil;
                break;
            }
        }

        return new PosicionEnPercentiles($tipo, $valor, $desde, $hasta, $curvas);
    }

    /**
     * Los siete percentiles por edad, punto por punto, para dibujar presión o pulso.
     *
     * @return array<int,array{anos:float, dias:int, curvas:array<int,float>}>
     */
    public function puntosDePercentiles(string $tipo, ?string $sexo): array
    {
        $puntos = [];
        foreach ($this->filasDeOtra($tipo, $sexo) as $fila) {
            $curvas = self::percentiles($fila);

            if ($curvas !== null) {
                $puntos[] = ['anos' => self::anosDe($fila), 'dias' => (int) $fila['edad_ini_dia'], 'curvas' => $curvas];
            }
        }

        usort($puntos, fn ($x, $y) => $x['anos'] <=> $y['anos']);

        return $puntos;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return array<int,array<string,mixed>> */
    private function filasDe(string $sexo): array
    {
        return $sexo === 'nina' ? $this->nina : $this->nino;
    }

    /**
     * Las filas de presión/pulso del sexo (la tabla trae `sexo`); si ninguna lo declara, o no hay del sexo pedido, se usan todas.
     *
     * @return array<int,array<string,mixed>>
     */
    private function filasDeOtra(string $tipo, ?string $sexo): array
    {
        $filas = $this->otras[$tipo] ?? [];
        $letra = strtoupper(trim((string) $sexo));
        $delSexo = array_values(array_filter($filas, fn ($f) => strtoupper(trim((string) ($f['sexo'] ?? ''))) === $letra));

        return $delSexo !== [] ? $delSexo : $filas;
    }

    /**
     * @param  array<int,array<string,mixed>>  $filas
     * @return array<string,mixed>|null
     */
    private static function filaPorEdad(array $filas, int $dias): ?array
    {
        if ($dias < 0) {
            return null;
        }

        usort($filas, fn ($x, $y) => (int) $x['edad_ini_dia'] <=> (int) $y['edad_ini_dia']);

        $elegida = null;
        $siguiente = null;
        foreach ($filas as $fila) {
            if ((int) $fila['edad_ini_dia'] > $dias) {
                $siguiente = $fila;
                break;
            }
            $elegida = $fila;
        }

        if ($elegida === null) {
            return null;
        }

        // Dentro de su rango, o en un hueco entre dos rangos (se usa el anterior); pasado el último rango no se extrapola.
        if ($dias <= (int) $elegida['edad_fin_dia'] || $siguiente !== null) {
            return $elegida;
        }

        return null;
    }

    /** @return array{b:float, m:float, a:float}|null */
    private static function curvas(array $fila, string $prefijo): ?array
    {
        $b = self::numero($fila[$prefijo . '_b'] ?? null);
        $m = self::numero($fila[$prefijo . '_m'] ?? null);
        $a = self::numero($fila[$prefijo . '_a'] ?? null);

        return $b === null || $m === null || $a === null ? null : ['b' => $b, 'm' => $m, 'a' => $a];
    }

    /** @return array<int,float>|null percentil => valor, sólo si están todos */
    private static function percentiles(array $fila): ?array
    {
        $curvas = [];
        foreach (self::PERCENTILES as $columna => $percentil) {
            $valor = self::numero($fila[$columna] ?? null);

            if ($valor === null) {
                return null;
            }
            $curvas[$percentil] = $valor;
        }

        return $curvas;
    }

    /** El eje de las gráficas: `anos` si la fila lo trae y, si no, los días de inicio entre 365,25. */
    private static function anosDe(array $fila): float
    {
        $anos = self::numero($fila['anos'] ?? null);

        return $anos ?? round(((int) $fila['edad_ini_dia']) / 365.25, 2);
    }

    private static function numero(mixed $valor): ?float
    {
        return $valor === null || $valor === '' || ! is_numeric($valor) ? null : (float) $valor;
    }
}
