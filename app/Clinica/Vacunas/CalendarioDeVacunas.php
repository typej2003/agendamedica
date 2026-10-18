<?php

namespace App\Clinica\Vacunas;

use Carbon\Carbon;

/**
 * **El calendario de vacunas de un niño** (WEB-7.4.4a), puro (sin base de datos): cruza el catálogo de vacunas **del médico** con lo que ya
 * se le aplicó al niño y dice, por dosis, cuándo le toca y en qué estado está.
 *
 *  - **Las dosis las arma cada médico** (`vacunas_dosis`: número, rótulo, edad en meses). Una vacuna **sin dosis** cargadas cuenta como una
 *    **dosis única** a su `edad_inicial_meses` (decisión del 2026-10-17: no hay calendario único). Sin ninguna de las dos, la vacuna se
 *    lista «sin edad definida».
 *  - **La fecha planeada** es el nacimiento + los meses de la dosis. El escritorio las guarda como filas `ejecutada = 'N'`; la web las calcula.
 *  - **Aplicada** = una fila de `vacuna_paciente` con `ejecutada = 'S'`; se asocia a la dosis por su `dosis_numero` (o, si no, por el rótulo o el
 *    número escrito en `dosis`). Lo aplicado que no encaja con ninguna dosis se lista aparte, sin perderlo.
 *  - Una vacuna de un sexo (`sexo` `M`/`F`; `A` o vacío = ambos) sólo se le ofrece a ese sexo.
 *
 *    Una aplicada sin número ni rótulo ocupa, por orden de fecha, la primera dosis libre.
 *
 * Estados: aplicada · atrasada (la fecha ya pasó) · próxima (en los próximos 30 días) · pendiente (más adelante) · sin fecha.
 */
final class CalendarioDeVacunas
{
    public const APLICADA = 'aplicada';
    public const ATRASADA = 'atrasada';
    public const PROXIMA = 'proxima';
    public const PENDIENTE = 'pendiente';
    public const SIN_FECHA = 'sin-fecha';

    /** Cuántos días adelante una dosis pendiente se considera «próxima». */
    public const DIAS_PROXIMA = 30;

    private const ROTULOS = [
        self::APLICADA => 'Aplicada',
        self::ATRASADA => 'Atrasada',
        self::PROXIMA => 'Próxima',
        self::PENDIENTE => 'Pendiente',
        self::SIN_FECHA => 'Sin fecha',
    ];

    public static function rotulo(string $estado): string
    {
        return self::ROTULOS[$estado] ?? $estado;
    }

    /**
     * @param  array<int,object>  $vacunas  las del médico (`codi_vacuna`, `vacuna`, `edad_inicial_meses`, `fija`, `cobertura`, `sexo`)
     * @param  array<string,array<int,object>>  $dosis  `codi_vacuna` => sus dosis (`dosis`, `dosis_descri`, `meses`, `sexo`)
     * @param  array<int,object>  $aplicadas  filas de `vacuna_paciente` del niño (`codi_vacuna`, `dosis`, `dosis_numero`, `ejecutada`, `fecha_ejecutada`, `lote`)
     * @return array{vacunas:array<int,array<string,mixed>>, otras:array<int,array<string,mixed>>, resumen:array<string,int>}
     */
    public function armar(?string $nacimiento, ?string $sexo, string $hoy, array $vacunas, array $dosis, array $aplicadas): array
    {
        $hoyFecha = Carbon::parse($hoy)->startOfDay();
        $nace = $nacimiento ? Carbon::parse($nacimiento)->startOfDay() : null;
        $letra = strtoupper(trim((string) $sexo));

        $aplicadasPorCodigo = [];
        foreach ($aplicadas as $fila) {
            if (strtoupper((string) ($fila->ejecutada ?? '')) === 'S') {
                $aplicadasPorCodigo[(string) $fila->codi_vacuna][] = $fila;
            }
        }

        $salida = [];
        $resumen = [self::APLICADA => 0, self::ATRASADA => 0, self::PROXIMA => 0, self::PENDIENTE => 0, self::SIN_FECHA => 0];
        $codigosDelMedico = [];

        foreach ($vacunas as $vacuna) {
            $codigo = (string) $vacuna->codi_vacuna;
            $codigosDelMedico[$codigo] = true;

            if (! self::aplicaAlSexo($vacuna->sexo ?? null, $letra)) {
                continue;
            }

            $filas = [];
            foreach ($this->dosisPlaneadas($vacuna, $dosis[$codigo] ?? [], $letra) as $d) {
                $filas[] = $d + ['aplicada' => null, 'lote' => null];
            }

            $sobrantes = $aplicadasPorCodigo[$codigo] ?? [];
            foreach ($filas as $i => $fila) {
                $indice = $this->buscarAplicada($sobrantes, $fila);
                if ($indice !== null) {
                    $filas[$i]['aplicada'] = self::fecha($sobrantes[$indice]->fecha_ejecutada ?? null);
                    $filas[$i]['lote'] = self::texto($sobrantes[$indice]->lote ?? null);
                    unset($sobrantes[$indice]);
                }
            }

            // Una aplicada sin número ni rótulo (la cargaron sin decir qué dosis era) ocupa, en orden de fecha, la primera dosis que siga libre.
            foreach ($sobrantes as $k => $extra) {
                if (($extra->dosis_numero ?? null) !== null || self::texto($extra->dosis ?? null) !== null) {
                    continue;
                }
                foreach ($filas as $i => $fila) {
                    if ($fila['aplicada'] === null) {
                        $filas[$i]['aplicada'] = self::fecha($extra->fecha_ejecutada ?? null);
                        $filas[$i]['lote'] = self::texto($extra->lote ?? null);
                        unset($sobrantes[$k]);
                        break;
                    }
                }
            }

            foreach ($filas as $i => $fila) {
                $filas[$i]['planeada'] = $nace !== null && $fila['meses'] !== null ? self::sumarMeses($nace, (float) $fila['meses'])->toDateString() : null;
                $filas[$i]['estado'] = self::estado($filas[$i]['planeada'], $filas[$i]['aplicada'], $hoyFecha);
            }

            // Lo aplicado que no encaja con ninguna dosis del calendario se muestra igual.
            foreach ($sobrantes as $extra) {
                $filas[] = [
                    'numero' => null, 'rotulo' => self::texto($extra->dosis ?? null) ?? 'Aplicada', 'meses' => null, 'planeada' => null,
                    'aplicada' => self::fecha($extra->fecha_ejecutada ?? null), 'lote' => self::texto($extra->lote ?? null), 'estado' => self::APLICADA,
                ];
            }

            foreach ($filas as $fila) {
                $resumen[$fila['estado']]++;
            }

            $salida[] = [
                'codigo' => $codigo,
                'vacuna' => trim((string) $vacuna->vacuna),
                'tipo' => ConvencionDeVacunas::fija($vacuna->fija ?? null),
                'cobertura' => self::texto($vacuna->cobertura ?? null),
                'sinEdad' => $filas === [],
                'dosis' => $filas,
            ];
        }

        // Aplicadas de vacunas que el médico no tiene en su catálogo (p. ej. una que borró): no se pierden.
        $otras = [];
        foreach ($aplicadasPorCodigo as $codigo => $filas) {
            if (isset($codigosDelMedico[$codigo])) {
                continue;
            }
            foreach ($filas as $fila) {
                $otras[] = [
                    'codigo' => $codigo, 'vacuna' => self::texto($fila->descripcion ?? null) ?? 'Vacuna ' . $codigo,
                    'rotulo' => self::texto($fila->dosis ?? null), 'aplicada' => self::fecha($fila->fecha_ejecutada ?? null),
                    'lote' => self::texto($fila->lote ?? null),
                ];
                $resumen[self::APLICADA]++;
            }
        }

        return ['vacunas' => $salida, 'otras' => $otras, 'resumen' => $resumen];
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * Las dosis que le tocan: las del médico (filtradas por sexo) o, si no tiene, una dosis única a la edad inicial de la vacuna.
     *
     * @param  array<int,object>  $dosis
     * @return array<int,array{numero:?int, rotulo:string, meses:?float}>
     */
    private function dosisPlaneadas(object $vacuna, array $dosis, string $letra): array
    {
        $propias = array_values(array_filter($dosis, fn ($d) => self::aplicaAlSexo($d->sexo ?? null, $letra)));
        usort($propias, fn ($a, $b) => (int) $a->dosis <=> (int) $b->dosis);

        if ($propias !== []) {
            return array_map(fn ($d) => [
                'numero' => (int) $d->dosis,
                'rotulo' => self::texto($d->dosis_descri ?? null) ?? (int) $d->dosis . 'ª dosis',
                'meses' => $d->meses === null ? null : (float) $d->meses,
            ], $propias);
        }

        // Con dosis cargadas pero ninguna para este sexo no hay nada que ofrecer; la dosis única es sólo para la vacuna sin dosis.
        if ($dosis !== [] || ($vacuna->edad_inicial_meses ?? null) === null) {
            return [];
        }

        return [['numero' => 1, 'rotulo' => 'Dosis única', 'meses' => (float) $vacuna->edad_inicial_meses]];
    }

    /**
     * La fila aplicada que corresponde a una dosis (su posición en `$aplicadas`) o `null`.
     *
     * @param  array<int,object>  $aplicadas
     * @param  array{numero:?int, rotulo:string}  $fila
     */
    private function buscarAplicada(array $aplicadas, array $fila): ?int
    {
        foreach ($aplicadas as $i => $a) {
            if ($fila['numero'] !== null && ($a->dosis_numero ?? null) !== null && (int) $a->dosis_numero === $fila['numero']) {
                return $i;
            }
        }

        foreach ($aplicadas as $i => $a) {
            if (($a->dosis_numero ?? null) !== null) {
                continue;
            }
            $texto = mb_strtolower(trim((string) ($a->dosis ?? '')));
            if ($texto !== '' && ($texto === mb_strtolower($fila['rotulo']) || ($fila['numero'] !== null && $texto === (string) $fila['numero']))) {
                return $i;
            }
        }

        return null;
    }

    private static function estado(?string $planeada, ?string $aplicada, Carbon $hoy): string
    {
        if ($aplicada !== null) {
            return self::APLICADA;
        }

        if ($planeada === null) {
            return self::SIN_FECHA;
        }

        $fecha = Carbon::parse($planeada)->startOfDay();

        return match (true) {
            $fecha->lt($hoy) => self::ATRASADA,
            $fecha->lte($hoy->copy()->addDays(self::DIAS_PROXIMA)) => self::PROXIMA,
            default => self::PENDIENTE,
        };
    }

    /** Nacimiento + meses (los decimales cuentan como 30 días por mes, como el escritorio). */
    private static function sumarMeses(Carbon $nacimiento, float $meses): Carbon
    {
        $enteros = (int) floor($meses);
        $fecha = $nacimiento->copy()->addMonthsNoOverflow($enteros);
        $resto = $meses - $enteros;

        return $resto > 0 ? $fecha->addDays((int) round($resto * 30)) : $fecha;
    }

    private static function aplicaAlSexo(?string $codigo, string $letra): bool
    {
        $codigo = strtoupper(trim((string) $codigo));

        return $codigo === '' || $codigo === 'A' || $letra === '' || $codigo === $letra;
    }

    private static function fecha(mixed $valor): ?string
    {
        return $valor === null || trim((string) $valor) === '' ? null : Carbon::parse($valor)->toDateString();
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
