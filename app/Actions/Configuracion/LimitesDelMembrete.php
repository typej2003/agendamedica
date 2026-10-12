<?php

namespace App\Actions\Configuracion;

/**
 * **Los textos del membrete que se guardan en `evolucion`** y su largo máximo (el del esquema del legado), en un solo
 * lugar para el API del móvil (`ConfiguracionMedicoController`) y la web clínica (WEB-8.2).
 *
 * La web imprime sólo `especialidad`, `rif` y las tres líneas de pie (`linea_1..3`); `ciudad` y el pie del informe
 * (`lineag_1..2`) los edita el móvil pero ninguna hoja de la web los usa todavía.
 */
final class LimitesDelMembrete
{
    /** `columna => largo máximo` de cada texto que el API edita. */
    public const POR_COLUMNA = [
        'especialidad' => 200,
        'rif' => 40,
        'ciudad' => 20,
        'linea_1' => 90,
        'linea_2' => 90,
        'linea_3' => 90,
        'lineag_1' => 115,
        'lineag_2' => 115,
        'plantilla_cita' => 500,
        'plantilla_cumple' => 500,
    ];

    /** Los textos que la hoja de la web imprime en el membrete y en el pie. */
    public const IMPRESOS = ['especialidad', 'rif', 'linea_1', 'linea_2', 'linea_3'];

    /** El largo máximo de una columna, o `null` si no es un texto del membrete. */
    public static function de(string $columna): ?int
    {
        return self::POR_COLUMNA[$columna] ?? null;
    }

    /** La regla de Laravel de un texto opcional del membrete (`nullable|string|max:N`). */
    public static function regla(string $columna): string
    {
        return 'nullable|string|max:' . self::POR_COLUMNA[$columna];
    }
}
