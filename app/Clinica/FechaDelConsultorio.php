<?php

namespace App\Clinica;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * "Qué día es hoy" y "qué hora es" **en el consultorio**, no en el servidor.
 *
 * Existe por una sola decisión: el saludo de cumpleaños (WEB-2.9) se manda a una **hora local** y a los
 * pacientes que cumplen años **ese día local**. Con `config('app.timezone')` en UTC —el default del
 * proyecto, que no se toca— un consultorio de Venezuela (UTC-4) estaría eligiendo la fecha y la hora
 * cuatro horas adelantado: a las 21:00 del 9 de octubre UTC ya es 10 de octubre en Caracas, y el envío
 * de las 09:00 saldría a las 05:00. La zona vive en `config/clinica.php`.
 *
 * Todo lo que decide "hoy" o "ahora" para el negocio de la clínica pasa por acá; el resto del proyecto
 * sigue con `now()` (UTC), que es lo correcto para marcas de tiempo.
 */
final class FechaDelConsultorio
{
    public static function zona(): string
    {
        // Sin aplicación levantada (tests unitarios puros de edades) no hay `config()`: se usa el default.
        try {
            return (string) config('clinica.zona', 'America/Caracas');
        } catch (\Throwable $e) {
            return 'America/Caracas';
        }
    }

    /** El momento actual en el consultorio. */
    public static function ahora(): CarbonInterface
    {
        return Carbon::now(self::zona());
    }

    /**
     * El día que se está viviendo en el consultorio (a medianoche de esa zona, para poder formatearlo y
     * compararlo sin arrastrar la hora).
     */
    public static function hoy(): CarbonInterface
    {
        return self::ahora()->startOfDay();
    }

    /**
     * El día de hoy **del consultorio** como fecha de calendario: medianoche, sin zona propia, igual que lo que
     * arma `Carbon::createFromFormat('!Y-m-d', …)` con la fecha de la URL. Es lo que usa la agenda para su día
     * por defecto, para marcar "hoy" y para comparar días: así la fecha pedida y la de hoy son comparables sin
     * mezclar zonas (a las 20:30 en Caracas es ya el día siguiente en UTC, y `Carbon::today()` daba ese).
     */
    public static function hoyComoFecha(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', self::hoy()->toDateString());
    }

    /** La fecha de hoy del consultorio como texto `AAAA-MM-DD`. */
    public static function hoyComoTexto(): string
    {
        return self::hoy()->toDateString();
    }

    /** ¿Esa fecha (`AAAA-MM-DD`, Carbon o fecha-hora) es el día de hoy del consultorio? Compara sólo el día. */
    public static function esHoy($fecha): bool
    {
        return self::textoDeFecha($fecha) === self::hoyComoTexto();
    }

    /** ¿Esa fecha es anterior al día de hoy del consultorio? Compara sólo el día. */
    public static function esAnteriorAHoy($fecha): bool
    {
        return self::textoDeFecha($fecha) < self::hoyComoTexto();
    }

    /** El día de calendario (`AAAA-MM-DD`) de lo que llegue, sin convertir de zona. */
    private static function textoDeFecha($fecha): string
    {
        if ($fecha instanceof \DateTimeInterface) {
            return $fecha->format('Y-m-d');
        }

        return substr(trim((string) $fecha), 0, 10);
    }

    /**
     * Interpreta una fecha pedida a mano (`--fecha=AAAA-MM-DD`, `?fecha=`) como un día **del
     * consultorio**. Sin esto, el `--fecha` de las pruebas se parsearía en UTC y una medianoche se
     * correría un día para atrás en la zona del consultorio.
     */
    public static function dia(?string $fecha): CarbonInterface
    {
        if ($fecha === null || trim($fecha) === '') {
            return self::hoy();
        }

        return Carbon::parse(trim($fecha), self::zona())->startOfDay();
    }
}
