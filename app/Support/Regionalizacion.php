<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * **Cómo se escriben las fechas y las horas para quien mira la pantalla** (WEB-13.5): el orden de la fecha,
 * su separador y el reloj de 12 o 24 h, tomados de la **regionalización del dispositivo**.
 *
 * Lo detecta el navegador (`public/js/regionalizacion.js`, con `Intl`) y lo manda en la cookie
 * {@see COOKIE} como `dmy|/|12`; el middleware `AplicarRegionalizacion` la valida y la deja en el
 * contenedor para ese request. Lo que no tiene un dispositivo detrás —un correo, un SMS, el WhatsApp, un
 * comando, un job, el API del móvil o del escritorio— usa {@see porDefecto()}: la convención del
 * consultorio (`config('clinica.regionalizacion')`, `dmy|/|12` = Venezuela).
 *
 * Qué **no** cambia con esto:
 *  - La **zona horaria** sigue siendo la del consultorio (`FechaDelConsultorio`): el «hoy» de la agenda no
 *    puede depender del equipo que la mira.
 *  - El **idioma**: los textos son en español; sólo cambia el orden y el reloj.
 *  - Lo que se **guarda** o viaja por el API: `AAAA-MM-DD` y `HH:MM` de 24 h.
 *
 * Sólo hay dos órdenes (`dmy` y `mdy`): los de los locales en español y portugués que detecta el navegador
 * (Panamá y Puerto Rico son `mdy`; el resto, `dmy`). Un `ymd` o cualquier otra cosa no se acepta y queda el
 * valor por defecto: con fechas clínicas, adivinar el orden es inventar el dato.
 */
final class Regionalizacion
{
    public const COOKIE = 'ddr_region';

    public const DMY = 'dmy';
    public const MDY = 'mdy';

    private const ORDENES = [self::DMY, self::MDY];
    private const SEPARADORES = ['/', '-', '.'];
    private const CICLOS = [12, 24];

    public function __construct(
        public readonly string $orden,
        public readonly string $separador,
        public readonly int $ciclo,
    ) {
    }

    /** La del request (la del dispositivo, si la mandó) o la del consultorio. */
    public static function actual(): self
    {
        return app()->bound(self::class) ? app(self::class) : self::porDefecto();
    }

    /** La convención del consultorio: lo que se usa cuando no hay un dispositivo que la diga. */
    public static function porDefecto(): self
    {
        // Sin aplicación armada (un test de PHPUnit puro) no hay `config`: vale la de Venezuela.
        $texto = app()->bound('config') ? (string) config('clinica.regionalizacion', '') : '';

        return self::desdeTexto($texto) ?? new self(self::DMY, '/', 12);
    }

    /** `dmy|/|12` → la regionalización, o `null` si el texto no es exactamente una de las válidas. */
    public static function desdeTexto(?string $texto): ?self
    {
        $partes = explode('|', trim((string) $texto));

        if (count($partes) !== 3) {
            return null;
        }

        [$orden, $separador, $ciclo] = $partes;

        if (! in_array($orden, self::ORDENES, true)
            || ! in_array($separador, self::SEPARADORES, true)
            || ! in_array($ciclo, ['12', '24'], true)) {
            return null;
        }

        return new self($orden, $separador, (int) $ciclo);
    }

    /** El formato de PHP de la fecha corta de quien mira (`d/m/Y`, `m/d/Y`…): para un `->format()`. */
    public static function formatoActual(): string
    {
        return self::actual()->formatoDeFecha();
    }

    public function comoTexto(): string
    {
        return $this->orden . '|' . $this->separador . '|' . $this->ciclo;
    }

    public function es24h(): bool
    {
        return $this->ciclo === 24;
    }

    /** El formato de PHP de una fecha corta: `d/m/Y`, `m/d/Y`, `d-m-Y`… */
    public function formatoDeFecha(): string
    {
        $s = $this->separador;

        return $this->orden === self::MDY ? "m{$s}d{$s}Y" : "d{$s}m{$s}Y";
    }

    /** Cómo se le dice a la persona que teclee una fecha: «dd/mm/aaaa» o «mm/dd/aaaa». */
    public function patronDeTecleo(): string
    {
        $s = $this->separador;

        return $this->orden === self::MDY ? "mm{$s}dd{$s}aaaa" : "dd{$s}mm{$s}aaaa";
    }

    /** Un ejemplo para el mensaje de error: el 9 de mayo de 2026 en este orden. */
    public function ejemploDeTecleo(): string
    {
        $s = $this->separador;

        return $this->orden === self::MDY ? "05{$s}09{$s}2026" : "09{$s}05{$s}2026";
    }

    public function fecha(CarbonInterface $momento): string
    {
        return $momento->format($this->formatoDeFecha());
    }

    /** Una hora de pared (0-23, 0-59) en el reloj del dispositivo: «1:05 PM» o «13:05». */
    public function hora(int $hora, int $minuto, ?int $segundo = null): string
    {
        $resto = sprintf(':%02d', $minuto) . ($segundo === null ? '' : sprintf(':%02d', $segundo));

        if ($this->es24h()) {
            return sprintf('%02d', $hora) . $resto;
        }

        $hora12 = $hora % 12 === 0 ? 12 : $hora % 12;

        return $hora12 . $resto . ($hora >= 12 ? ' PM' : ' AM');
    }

    /** Los atributos del `<html>` que leen los scripts de entrada (`hora-ampm.js`, `mascara-fecha.js`). */
    public function atributosHtml(): string
    {
        return sprintf(
            'data-region="%s" data-fecha-orden="%s" data-fecha-separador="%s" data-hora-ciclo="%d"',
            e($this->comoTexto()),
            e($this->orden),
            e($this->separador),
            $this->ciclo,
        );
    }
}
