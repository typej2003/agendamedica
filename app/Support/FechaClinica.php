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

    /**
     * ¿Es una de las fechas nulas del legado? Además de `0000-00-00` hay que cubrir toda la familia
     * `0000-00-*`: MySQL acepta `0000-00-01` y Carbon lo convierte en el **año -1**, que no revienta pero
     * da una edad absurda (medido: 2026 años).
     */
    private static function esNula(string $texto): bool
    {
        return in_array($texto, self::NULAS, true) || str_starts_with($texto, '0000-00');
    }

    /**
     * Una fecha del legado para la pantalla. Sin `$formato`, la fecha corta en el orden del dispositivo
     * (`Regionalizacion`, WEB-13.5); un formato explícito se respeta (los mensajes al paciente fijan `d/m/Y`).
     */
    public static function formato($valor, ?string $formato = null): string
    {
        $formato ??= Regionalizacion::actual()->formatoDeFecha();

        $texto = trim((string) $valor);

        if (self::esNula($texto)) {
            return '—';
        }

        try {
            return Carbon::parse($texto)->format($formato);
        } catch (Throwable $e) {
            return $texto;
        }
    }

    /**
     * Una fecha del legado lista para un `<input type="date">`: `AAAA-MM-DD`, o **vacío** cuando la fecha
     * no sirve (nula, basura, o un año imposible que Carbon sí sabe leer).
     *
     * El último caso es el que obliga a que esto exista: `fnacimiento` está casteado a `date` en el modelo,
     * así que el `0000-00-01` de la base ya llega convertido en un objeto del **año -1**, que
     * `esNula()` no reconoce por su texto y que el navegador mostraría como una fecha rara. El rango útil
     * es el mismo de `edad()`: nada antes de 1900 ni en el futuro.
     */
    public static function paraInput($valor): string
    {
        $texto = trim((string) $valor);

        if (self::esNula($texto)) {
            return '';
        }

        try {
            $fecha = Carbon::parse($texto);
        } catch (Throwable $e) {
            return '';
        }

        if ($fecha->year < 1900 || $fecha->greaterThan(\App\Clinica\FechaDelConsultorio::hoyComoFecha())) {
            return '';
        }

        return $fecha->format('Y-m-d');
    }

    /** Edad en años cumplidos, o `null` si no hay fecha válida. */
    public static function edad($fnacimiento): ?int
    {
        $texto = trim((string) $fnacimiento);

        if (self::esNula($texto)) {
            return null;
        }

        try {
            $fecha = Carbon::parse($texto);
        } catch (Throwable $e) {
            return null;
        }

        // Una fecha de nacimiento en el futuro o con un año imposible tampoco da edad: `diffInYears` es
        // absoluto, así que un `2099-01-01` mal cargado mostraba "73 años" en un recién nacido.
        if ($fecha->year < 1900 || $fecha->greaterThan(\App\Clinica\FechaDelConsultorio::hoyComoFecha())) {
            return null;
        }

        // Carbon 3 devuelve `float` en `diffInYears`; la edad se muestra y compara como entero.
        return (int) $fecha->diffInYears(\App\Clinica\FechaDelConsultorio::hoyComoFecha());
    }

    /**
     * La edad como la dice un pediatra (WEB-7.4.2d): «5 días», «3 meses 12 días», «2 años 4 meses». En un adulto es sólo «34 años» (los meses
     * no aportan), así que a partir de los 6 años se devuelve el mismo texto de siempre. `null` si no hay fecha válida.
     */
    public static function edadDetallada($fnacimiento): ?string
    {
        $anios = self::edad($fnacimiento);

        if ($anios === null) {
            return null;
        }

        if ($anios >= 6) {
            return $anios . ' años';
        }

        $nacimiento = Carbon::parse(trim((string) $fnacimiento));
        $diferencia = $nacimiento->diff(\App\Clinica\FechaDelConsultorio::hoyComoFecha());
        $partes = [];

        if ($diferencia->y > 0) {
            $partes[] = $diferencia->y . ($diferencia->y === 1 ? ' año' : ' años');
        }
        if ($diferencia->m > 0) {
            $partes[] = $diferencia->m . ($diferencia->m === 1 ? ' mes' : ' meses');
        }
        // Los días sólo importan en los primeros meses.
        if ($diferencia->y === 0 && $diferencia->d > 0 && $diferencia->m < 6) {
            $partes[] = $diferencia->d . ($diferencia->d === 1 ? ' día' : ' días');
        }

        return $partes === [] ? '0 días' : implode(' ', $partes);
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

        return self::diaSemana($dia) . ' ' . $dia->format(\App\Support\Regionalizacion::formatoActual());
    }

    /** `octubre 2026`: el título de la vista de mes. */
    public static function tituloMes($fecha): string
    {
        $dia = Carbon::parse($fecha);

        return self::MESES[(int) $dia->format('n')] . ' ' . $dia->format('Y');
    }
}
