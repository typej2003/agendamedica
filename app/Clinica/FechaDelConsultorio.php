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
        return (string) config('clinica.zona', 'America/Caracas');
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
