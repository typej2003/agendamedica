<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fechas del legado. En las tablas conviven `NULL`, cadenas vacías, `0000-00-00` y fechas buenas, así que
 * la vista no puede parsear a ciegas: lo que no se puede interpretar se muestra tal cual, nunca se
 * revienta la pantalla por un dato viejo.
 */
final class FechaClinica
{
    private const NULAS = ['', '0000-00-00', '0000-00-00 00:00:00', 'null', 'NULL'];

    public static function formato($valor, string $formato = 'd/m/Y'): string
    {
        $texto = trim((string) $valor);

        if ($texto === '' || in_array($texto, self::NULAS, true)) {
            return '—';
        }

        try {
            return Carbon::parse($texto)->format($formato);
        } catch (Throwable $e) {
            return $texto;
        }
    }

    /** Edad en años cumplidos, o `null` si no hay fecha válida. */
    public static function edad($fnacimiento): ?int
    {
        $texto = trim((string) $fnacimiento);

        if ($texto === '' || in_array($texto, self::NULAS, true)) {
            return null;
        }

        try {
            return Carbon::parse($texto)->diffInYears(Carbon::now());
        } catch (Throwable $e) {
            return null;
        }
    }

    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /** Nombre del día en español (`lunes`). Carbon traduce, pero el locale del servidor no está garantizado. */
    public static function diaSemana($fecha): string
    {
        return self::DIAS[Carbon::parse($fecha)->dayOfWeek];
    }

    /** `lunes 06/10/2026`: el título de la vista de día. */
    public static function tituloDia($fecha): string
    {
        $dia = Carbon::parse($fecha);

        return self::diaSemana($dia) . ' ' . $dia->format('d/m/Y');
    }

    /** `octubre 2026`: el título de la vista de mes. */
    public static function tituloMes($fecha): string
    {
        $dia = Carbon::parse($fecha);

        return self::MESES[(int) $dia->format('n')] . ' ' . $dia->format('Y');
    }
}
