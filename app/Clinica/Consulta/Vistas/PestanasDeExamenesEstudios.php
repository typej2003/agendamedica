<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\TextosDelExamen;
use Closure;

/**
 * **Las tres solapas de *Exámenes/Estudios*** (ítem 6 de la consulta pediátrica, 2026-10-19): *Exámenes*,
 * *Imagenología* y *Estudios exploratorios*, en el orden del escritorio (`tab_3` de `tabpage_signos`,
 * `w_consulta_principal.srw:1650-1670`).
 *
 * Es **el único lugar donde se agrega una solapa** de esta sección, como `PestanasDelExamen` y
 * `PestanasDeSolicitudes`. Las claves son las de `TextosDelExamen` —el mismo texto en una columna de
 * `consultas`, con sus plantillas— así que la pantalla, la lectura y la escritura se comparten con las solapas
 * del examen: lo único propio de esta sección es **cuáles** se ofrecen y en qué orden.
 *
 * Son enlaces (`?seccion=examenes-estudios&pestana=imagenologia`), no JavaScript: funcionan sin script y la
 * solapa abierta se puede compartir y recargar. Sin base de datos.
 */
final class PestanasDeExamenesEstudios
{
    /** La solapa con la que abre la sección (la primera del escritorio). */
    public const POR_DEFECTO = TextosDelExamen::EXAMENES;

    /**
     * Las solapas, en el orden del escritorio. El rótulo sale de `TextosDelExamen` (la misma fuente que el
     * título de la tarjeta) para que un cambio de nombre no haya que hacerlo dos veces.
     *
     * @var array<int,string>
     */
    private const TODAS = [
        TextosDelExamen::EXAMENES,
        TextosDelExamen::IMAGENOLOGIA,
        TextosDelExamen::EXPLORATORIOS,
    ];

    /**
     * @param  Closure(string):string  $url  arma la dirección de una solapa (la resuelve el controlador)
     */
    public function __construct(private Closure $url)
    {
    }

    /** @return array<int,string> las claves de las solapas, en su orden */
    public static function claves(): array
    {
        return self::TODAS;
    }

    public static function existe(?string $clave): bool
    {
        return $clave !== null && in_array($clave, self::TODAS, true);
    }

    /** La clave de la solapa que se muestra: la pedida si existe y, si no, la primera. */
    public static function normalizar(?string $clave): string
    {
        return self::existe(trim((string) $clave)) ? trim((string) $clave) : self::POR_DEFECTO;
    }

    /**
     * Las opciones para `<x-ui.chips>`.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function opciones(string $activa): array
    {
        $opciones = [];

        foreach (self::TODAS as $clave) {
            $opciones[] = [
                'texto' => TextosDelExamen::titulo($clave),
                'url' => ($this->url)($clave),
                'activa' => $clave === $activa,
            ];
        }

        return $opciones;
    }

    /** El icono de una solapa (el de su texto en `TextosDelExamen`). */
    public static function icono(string $clave): string
    {
        return TextosDelExamen::icono($clave);
    }
}
