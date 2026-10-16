<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\TextosDelExamen;
use Closure;

/**
 * **Las solapas del examen** (WEB-6.5, el `tab_7` de `tabpage_examen` del escritorio): el catálogo de la fila de
 * solapas del ítem 4 del panel, y **el único lugar donde se agrega una solapa**.
 *
 * Las del escritorio son seis —*Examen Físico* (`tabpage_29`), *Examen Ginecológico* (`tabpage_30`),
 * *Colposcopía* (`tabpage_31`), *Citología* (`tabpage_33`), *Histeroscopía* (`tabpage_49`) y *Resultado de
 * exámenes* (`tabpage_48`)— y la web tiene cinco: la **evaluación física** (WEB-4.3) es una sola solapa que
 * cubre las dos primeras —el examen general y el ginecológico viven en la misma pantalla desde F4— y las otras
 * cuatro son las solapas de texto de `TextosDelExamen`, que traen su rótulo y su icono de ahí.
 *
 * ⚠️ **La clave de una solapa es su contrato**: es el `?pestana=`, el nombre de su parcial
 * (`clinica.consulta._evaluacion-<clave>`, ver `SeccionDeEvaluacionVista`) y el `name` del campo del texto. Un
 * desajuste entre la clave y el nombre del archivo es un **500** (trampa 114).
 */
final class PestanasDelExamen
{
    /** La evaluación física (WEB-4.3): el examen general y el ginecológico. */
    public const FISICO = 'fisico';

    /** El examen ginecológico (WEB-11.9): chip propio, como la solapa del escritorio. */
    public const GINECOLOGICO = 'ginecologico';

    /** La colposcopía, que es la primera solapa de texto (y por eso tiene constante propia). */
    public const COLPOSCOPIA = TextosDelExamen::COLPOSCOPIA;

    public const POR_DEFECTO = self::FISICO;

    private const ROTULOS = [
        self::FISICO => 'Examen físico',
        self::GINECOLOGICO => 'Examen ginecológico',
    ];

    private const ICONOS = [
        self::FISICO => 'bi-clipboard2-pulse',
        self::GINECOLOGICO => 'bi-gender-female',
    ];

    /**
     * @param  Closure(string):string  $url  arma la dirección de una solapa (el controlador la resuelve)
     */
    public function __construct(private Closure $url)
    {
    }

    /**
     * La clave de la solapa que se va a mostrar: la pedida si existe y, si no (o si no se pidió ninguna), la
     * que abre la sección.
     */
    public static function normalizar(?string $clave): string
    {
        $clave = $clave === null ? '' : trim($clave);

        return self::existe($clave) ? $clave : self::POR_DEFECTO;
    }

    public static function existe(?string $clave): bool
    {
        return $clave !== null && in_array($clave, self::claves(), true);
    }

    /** Las claves, en el orden del escritorio. */
    public static function claves(): array
    {
        return array_merge([self::FISICO, self::GINECOLOGICO], TextosDelExamen::claves());
    }

    /**
     * Las solapas para el segmentado de `<x-ui.segmentado>`, que espera `texto`, `url` y `activa`.
     *
     * @return array<int,array{texto:string, url:string, activa:bool, clave:string, icono:string}>
     */
    public function opciones(string $activa): array
    {
        return array_map(fn (string $clave) => [
            'texto' => self::rotulo($clave),
            'url' => ($this->url)($clave),
            'activa' => $clave === $activa,
            // La clave y el icono no los usa el control: los deja para quien tenga que distinguir la solapa.
            'clave' => $clave,
            'icono' => self::icono($clave),
        ], self::claves());
    }

    public static function rotulo(string $clave): string
    {
        return self::ROTULOS[$clave] ?? TextosDelExamen::titulo($clave);
    }

    public static function icono(string $clave): string
    {
        return self::ICONOS[$clave] ?? TextosDelExamen::icono($clave);
    }

    /** ¿La solapa es una de las de texto? (Las cuatro comparten el ViewModel y el parcial.) */
    public static function esDeTexto(string $clave): bool
    {
        return TextosDelExamen::existe($clave);
    }
}
