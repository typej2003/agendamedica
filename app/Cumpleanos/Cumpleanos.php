<?php

namespace App\Cumpleanos;

use Carbon\CarbonInterface;

/**
 * La **regla del cumpleaños** (WEB-2.9), sola y sin base de datos: qué días de nacimiento se saludan
 * en una fecha dada, y el **29 de febrero**.
 *
 * **La fecha del cumpleaños es día y mes de `pacientes.fnacimiento`**, no la fecha completa: el paciente
 * cumple todos los años el mismo día, y el año de nacimiento solo sirve para la edad. Los datos del
 * legado traen `NULL`, `''`, `0000-00-00` y textos con formato roto (`FechaClinica` ya documenta eso),
 * así que acá se decide qué es una fecha usable y qué no.
 *
 * **El 29 de febrero (decisión del 2026-10-10).** Los que nacieron un 29 de febrero cumplen de verdad
 * cada cuatro años; en los otros tres no existe su día. Se decidió que **se los saluda el 28 de
 * febrero**: es la convención que ya usan la mayoría de los sistemas de cumpleaños y la que el
 * paciente espera —el mes de su cumpleaños sí llega, y esperar al 1 de marzo sería saludarlo tarde—;
 * la alternativa (saltearlos) dejaría a una persona sin saludo tres años de cada cuatro, y la otra
 * (saludarlos el 1 de marzo) los saludaría en el mes equivocado. La pantalla lo dice ("29/02, se
 * adelanta al 28") para que no parezca un error.
 *
 * Se hace en **una sola dirección**: el 28 de febrero se saluda a los del 28 **y** a los del 29. Nunca
 * se saluda dos veces a nadie: el 29 real (año bisiesto) es otro día y su saludo es el suyo.
 */
final class Cumpleanos
{
    /** El día que se usa cuando el mes del 29/02 no lo tiene. */
    public const DIA_DEL_ADELANTO = 28;

    /**
     * Año con el que se valida que un "día y mes" exista. Es bisiesto **a propósito**: es el único que
     * tiene el 29 de febrero, así que acepta todos los cumpleaños posibles.
     */
    private const ANIO_DE_REFERENCIA = 2000;

    /** Mes del adelanto: febrero. */
    private const MES_DEL_ADELANTO = 2;

    /**
     * Los `m-d` que se saludan en el día pedido.
     *
     * Normalmente uno solo (`'10-05'`). El 28 de febrero de un **año no bisiesto** devuelve dos, porque
     * además de los que cumplen ese día se adelanta el 29.
     *
     * @return list<string> `m-d` con dos dígitos cada uno, como los devuelve `substr($fecha, 5, 5)`
     */
    public static function diasQueSeSaludan(CarbonInterface $dia): array
    {
        $mesDia = $dia->format('m-d');

        // El 29 real solo existe en año bisiesto, y ese día se saluda a los del 29 (nadie más).
        if ($mesDia === '02-29') {
            return ['02-29'];
        }

        if ($mesDia === '02-' . self::DIA_DEL_ADELANTO && ! $dia->isLeapYear()) {
            return ['02-' . self::DIA_DEL_ADELANTO, '02-29'];
        }

        return [$mesDia];
    }

    /**
     * ¿Este `fnacimiento` cumple años el día que se está saludando?
     *
     * Es la comprobación por fila del mismo criterio que `diasQueSeSaludan()` usa en la consulta: sirve
     * para probar la regla sin base de datos y para que la pantalla y el comando no tengan dos versiones.
     */
    public static function caeEl($fnacimiento, CarbonInterface $dia): bool
    {
        $mesDia = self::mesDia($fnacimiento);

        return $mesDia !== null && in_array($mesDia, self::diasQueSeSaludan($dia), true);
    }

    /** ¿El saludo de este paciente en este día es el de su fecha real, o el 29/02 adelantado al 28? */
    public static function esElDiaExacto($fnacimiento, CarbonInterface $dia): bool
    {
        return self::mesDia($fnacimiento) === $dia->format('m-d');
    }

    /** El año de `fnacimiento`, o `null` si no es una fecha usable. */
    public static function anioDeNacimiento($fnacimiento): ?int
    {
        $texto = self::textoUsable($fnacimiento);

        if ($texto === null) {
            return null;
        }

        $anio = (int) substr($texto, 0, 4);

        return $anio > 0 ? $anio : null;
    }

    /** `m-d` de `fnacimiento`, o `null` si no es una fecha usable. */
    public static function mesDia($fnacimiento): ?string
    {
        $texto = self::textoUsable($fnacimiento);

        return $texto === null ? null : substr($texto, 5, 5);
    }

    /**
     * El texto de la fecha tal como sale de la columna, o `null` si no se puede leer.
     *
     * Acepta lo que el legado guarda de verdad (`AAAA-MM-DD` y `AAAA-MM-DD 00:00:00`) y también un
     * `Carbon`: el modelo `Paciente` **castea** `fnacimiento` a fecha, así que cuando la fila viene de
     * Eloquent lo que llega acá es un objeto, no el texto de la columna. Es el mismo dato por los dos
     * caminos —el legado y el modelo— y por eso se aceptan los dos.
     *
     * Lo que no se puede leer —vacío, `0000-00-00`, texto libre, un 31 de un mes de 30— no tiene
     * cumpleaños: se descarta en silencio, que es lo que ya hace `FechaClinica` con las fechas rotas.
     */
    private static function textoUsable($fnacimiento): ?string
    {
        if ($fnacimiento instanceof \DateTimeInterface) {
            $fnacimiento = $fnacimiento->format('Y-m-d');
        }

        $texto = trim((string) $fnacimiento);

        if (strlen($texto) < 10 || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $partes)) {
            return null;
        }

        $anio = (int) $partes[1];
        $mes = (int) $partes[2];
        $dia = (int) $partes[3];

        if ($anio < 1) {
            return null;
        }

        // El mes y el día se validan **contra un año bisiesto de referencia**, no contra el año de
        // nacimiento: un cumpleaños es "día y mes" (decisión del 2026-10-10) y el 29 de febrero es una
        // fecha válida aunque el año que quedó guardado no lo fuera —lo que pasa con la fecha de
        // nacimiento "de relleno" de un paciente cargado a mano—. Un 30 de febrero o un 31 de abril sí
        // se descartan: no existen en ningún año.
        if (! checkdate($mes, $dia, self::ANIO_DE_REFERENCIA)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }
}
