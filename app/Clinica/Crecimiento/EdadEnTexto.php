<?php

namespace App\Clinica\Crecimiento;

use Carbon\Carbon;
use DateInterval;

/**
 * La edad entre dos fechas como la escribe el escritorio de pediatría: «2 años 4 meses 3 días» (WEB-7.4.3) y, en los
 * impresos, su forma corta «21A 10M 16D» (`compacta()`, 2026-10-20). Sin base de datos.
 */
final class EdadEnTexto
{
    /** La edad detallada: «2 años 4 meses 3 días». `null` si falta alguna fecha, no se entiende, o la segunda es anterior al nacimiento. */
    public static function entre(?string $nacimiento, ?string $fecha): ?string
    {
        $d = self::diferencia($nacimiento, $fecha);

        if ($d === null) {
            return null;
        }

        $partes = [];

        if ($d->y > 0) {
            $partes[] = $d->y . ($d->y === 1 ? ' año' : ' años');
        }
        if ($d->m > 0) {
            $partes[] = $d->m . ($d->m === 1 ? ' mes' : ' meses');
        }
        if ($d->d > 0 || $partes === []) {
            $partes[] = $d->d . ($d->d === 1 ? ' día' : ' días');
        }

        return implode(' ', $partes);
    }

    /**
     * **La edad del encabezado de la consulta**, para cualquier especialidad (Alexander, 2026-10-29): a los menores de
     * 6 años con meses y días, como la lee un pediatra; de ahí en adelante, sólo los años cumplidos («36 años»), que es
     * lo que importa en un adulto. Se cuenta **a la fecha de la consulta**, no a hoy.
     *
     * `null` si falta alguna fecha, no se entiende, o la segunda es anterior al nacimiento.
     */
    public static function aLaFecha(?string $nacimiento, ?string $fecha): ?string
    {
        $d = self::diferencia($nacimiento, $fecha);

        if ($d === null) {
            return null;
        }

        if ($d->y >= 6) {
            return $d->y . ' años';
        }

        return self::entre($nacimiento, $fecha);
    }

    /**
     * La edad **compacta** del impreso del escritorio de pediatría: «21A 10M 16D» (años, meses y días). Es la de la
     * cabecera del récipe —`EDAD: 21A 10M 16D`— y no reemplaza a `entre()`, que es el texto largo de la consulta.
     *
     * Los años y los meses salen **sólo si son mayores que cero** —un bebé de cinco días dice «5D», y el que nació el
     * mismo día del documento, «0D»—; los días van siempre, que es lo que hace legible la edad de un recién nacido.
     *
     * `null` si falta alguna fecha, no se entiende, o la segunda es anterior al nacimiento.
     */
    public static function compacta(?string $nacimiento, ?string $fecha): ?string
    {
        $d = self::diferencia($nacimiento, $fecha);

        if ($d === null) {
            return null;
        }

        $partes = [];

        if ($d->y > 0) {
            $partes[] = $d->y . 'A';
        }
        if ($d->m > 0) {
            $partes[] = $d->m . 'M';
        }

        $partes[] = $d->d . 'D';

        return implode(' ', $partes);
    }

    /**
     * Los años, meses y días entre el nacimiento y la fecha del documento, o `null` si no se pueden contar. Lo comparten
     * las dos formas de la edad (la larga y la compacta) para que no digan cosas distintas.
     */
    private static function diferencia(?string $nacimiento, ?string $fecha): ?DateInterval
    {
        if (! $nacimiento || ! $fecha) {
            return null;
        }

        try {
            $desde = Carbon::parse($nacimiento)->startOfDay();
            $hasta = Carbon::parse($fecha)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($hasta->lt($desde)) {
            return null;
        }

        return $desde->diff($hasta);
    }
}
