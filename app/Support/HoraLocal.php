<?php

namespace App\Support;

use App\Clinica\FechaDelConsultorio;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Cómo se **muestran** las horas a las personas: en la hora del consultorio y con el reloj y el orden de fecha
 * de su dispositivo (`Regionalizacion`, WEB-13.5: 12 h con AM/PM o 24 h; `d/m/Y` o `m/d/Y`). Sin dispositivo
 * (correo, SMS, comandos) vale la convención del consultorio: 12 h y `d/m/Y`.
 *
 * Hay dos clases de hora y no se mezclan:
 *
 *  - **Instante** (`created_at`, `updated_at`, `sync_changes.created_at`, el momento de un envío…): se guarda
 *    en UTC (`config('app.timezone')`). Para mostrarlo se **convierte** a la zona del consultorio
 *    (`config('clinica.zona')`, Caracas por defecto): `instante()`, `fechaHora()`, `hora()`.
 *  - **Hora local** (`cola.hora_ini`, los bloques de `office_schedules`, la hora del recordatorio o del
 *    cumpleaños): ya es la hora de pared del consultorio, `HH:MM[:SS]`. **No** se convierte de zona; sólo se
 *    formatea: `hora12()`, `rango()`.
 *
 * Lo que viaja a la base, al API del móvil o al sync del escritorio sigue en `HH:MM` de 24 h: esta clase es
 * sólo para lo que lee una persona. La entrada de horas es `<x-ui.hora>` (`public/js/clinica/hora-ampm.js`).
 */
final class HoraLocal
{
    /**
     * `HH:MM[:SS]` (24 h, hora del consultorio) → «8:00 AM», «1:00 PM», «12:30 PM», «12:00 AM».
     * Un valor vacío o sin forma de hora se devuelve como `$si_vacio` (por defecto, cadena vacía).
     */
    public static function hora12(?string $hhmm, string $siVacio = ''): string
    {
        $valor = trim((string) $hhmm);

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/', $valor, $m)) {
            return $siVacio;
        }

        $hora = (int) $m[1];
        $minuto = (int) $m[2];

        if ($hora > 23 || $minuto > 59) {
            return $siVacio;
        }

        $sufijo = $hora >= 12 ? 'PM' : 'AM';
        $hora12 = $hora % 12;
        $hora12 = $hora12 === 0 ? 12 : $hora12;

        return $hora12 . ':' . $m[2] . ' ' . $sufijo;
    }

    /** `H:MM[:SS]` → `HH:MM` (24 h), o cadena vacía si no es una hora: lo que lleva el hidden de `<x-ui.hora>`. */
    public static function hora24(?string $valor): string
    {
        if (self::hora12($valor) === '') {
            return '';
        }

        [$hora, $minuto] = explode(':', trim((string) $valor));

        return sprintf('%02d:%s', (int) $hora, substr($minuto, 0, 2));
    }

    /**
     * `HH:MM[:SS]` (24 h, hora del consultorio) en el reloj de quien mira: «1:00 PM» o «13:00». Es la que usa la
     * pantalla; `hora12()` es la de 12 h fija (los mensajes al paciente, que no tienen dispositivo).
     */
    public static function reloj(?string $hhmm, string $siVacio = ''): string
    {
        if (self::hora12($hhmm) === '') {
            return $siVacio;
        }

        [$hora, $minuto] = explode(':', trim((string) $hhmm));

        return Regionalizacion::actual()->hora((int) $hora, (int) substr($minuto, 0, 2));
    }

    /** Dos horas locales `HH:MM[:SS]` → «8:00 AM a 1:00 PM» (o «08:00 a 13:00»). */
    public static function rango(?string $desde, ?string $hasta): string
    {
        return self::reloj($desde) . ' a ' . self::reloj($hasta);
    }

    /** La fecha corta de un instante UTC, en la zona del consultorio y en el orden del dispositivo. */
    public static function fecha($valor, string $siVacio = ''): string
    {
        $momento = self::instante($valor);

        return $momento === null ? $siVacio : Regionalizacion::actual()->fecha($momento);
    }

    /**
     * Un instante UTC (Carbon, `DateTime` o texto de la base) en la zona del consultorio. `null` si no hay valor
     * o no se puede leer. Un texto sin zona se interpreta como UTC, que es como lo guarda la base.
     */
    public static function instante($valor): ?CarbonInterface
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            $momento = $valor instanceof \DateTimeInterface
                ? Carbon::instance($valor)->copy()
                : Carbon::parse((string) $valor, 'UTC');
        } catch (\Throwable $e) {
            return null;
        }

        return $momento->timezone(FechaDelConsultorio::zona());
    }

    /** La hora de un instante UTC, en hora del consultorio y el reloj del dispositivo («1:00 PM»). */
    public static function hora($valor): string
    {
        $momento = self::instante($valor);

        return $momento === null ? '' : Regionalizacion::actual()->hora($momento->hour, $momento->minute);
    }

    /**
     * «20/10/2026 1:00 PM»: la fecha y la hora de un instante UTC, en la hora del consultorio. `$region` fija la
     * regionalización (un correo no tiene dispositivo: `Regionalizacion::porDefecto()`).
     */
    public static function fechaHora($valor, string $siVacio = '', ?Regionalizacion $region = null): string
    {
        $momento = self::instante($valor);

        if ($momento === null) {
            return $siVacio;
        }

        $region ??= Regionalizacion::actual();

        return $region->fecha($momento) . ' ' . $region->hora($momento->hour, $momento->minute);
    }

    /** Igual que `fechaHora()` pero con los segundos: «20/10/2026 1:00:05 PM» (el detalle de la bitácora). */
    public static function fechaHoraConSegundos($valor, string $siVacio = ''): string
    {
        $momento = self::instante($valor);

        if ($momento === null) {
            return $siVacio;
        }

        $region = Regionalizacion::actual();

        return $region->fecha($momento) . ' ' . $region->hora($momento->hour, $momento->minute, $momento->second);
    }

    /** «hace 5 minutos» calculado sobre el instante (no depende de la zona), en español. */
    public static function haceCuanto($valor): string
    {
        $momento = self::instante($valor);

        return $momento === null ? '' : $momento->locale('es')->diffForHumans();
    }
}
