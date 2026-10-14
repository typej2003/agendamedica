<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;

/**
 * **Las solapas del examen de la consulta** (WEB-6.5, ítem 4 del panel del legado): Evaluación física ·
 * Colposcopía · … (las tres que se agregan —citología, histeroscopía y resultado de exámenes— entran con sus
 * tareas).
 *
 * El escritorio tiene estas solapas en el `tab_7` de `tabpage_examen` (`w_consulta_principal.srw`), **dentro
 * del examen**: *Examen Físico* (`tabpage_29`), *Examen Ginecológico* (`tabpage_30`), *Colposcopía*
 * (`tabpage_31`), *Citología* (`tabpage_33`), *Histeroscopía* (`tabpage_49`) y *Resultado de exámenes*
 * (`tabpage_48`). La web juntó las **dos primeras** en una sola sección —son la evaluación física de
 * WEB-4.3, que escribe las mismas dos tablas— y las demás son una solapa cada una.
 *
 * Son enlaces —cada solapa es una URL de la misma sección (`?seccion=evaluacion&pestana=colposcopia`)—, no
 * JavaScript, como las de las solicitudes (`PestanasDeSolicitudes`) y las del embarazo: funcionan sin
 * script y la solapa activa se puede compartir y recargar.
 *
 * `TODAS` es la lista de solapas **que existen hoy**, y es el único lugar donde se agregan: una solapa nueva
 * suma su fila y su parcial `_evaluacion-<clave>`. **Una que todavía no existe no se muestra.** Sin base de
 * datos.
 */
final class PestanasDelExamen
{
    /**
     * La solapa de la **evaluación física** (WEB-4.3): el *Examen Físico* y el *Examen Ginecológico* del
     * escritorio, que la web junta en una sola pantalla porque escriben las mismas dos tablas.
     *
     * La clave es `fisico` —y no `evaluacion`— porque el parcial de una solapa se **deriva** de su clave
     * (`_evaluacion-<clave>`), como en las secciones de solicitudes y del embarazo: el rótulo que ve el
     * médico es el de la solapa, no la clave de la URL.
     */
    public const FISICO = 'fisico';

    public const COLPOSCOPIA = 'colposcopia';

    /** La solapa con la que se abre la sección. */
    public const POR_DEFECTO = self::FISICO;

    /** @var array<string,array{texto:string, icono:string}> */
    private const TODAS = [
        self::FISICO => ['texto' => 'Evaluación física', 'icono' => 'bi-heart-pulse'],
        self::COLPOSCOPIA => ['texto' => 'Colposcopía', 'icono' => 'bi-search-heart'],
    ];

    /**
     * @param  Closure  $url  `fn(string $clave): string` — la URL de esa solapa (la resuelve el controlador)
     */
    public function __construct(private Closure $url)
    {
    }

    /** La clave de una solapa que existe; cualquier otro valor de la URL es la solapa por defecto. */
    public static function normalizar(?string $clave): string
    {
        return $clave !== null && isset(self::TODAS[$clave]) ? $clave : self::POR_DEFECTO;
    }

    /** ¿La clave es una solapa que existe? Lo usa la **escritura** para rechazar (404) una que no existe. */
    public static function existe(?string $clave): bool
    {
        return $clave !== null && isset(self::TODAS[$clave]);
    }

    /** @return array<int,string> las claves de las solapas que existen */
    public static function claves(): array
    {
        return array_keys(self::TODAS);
    }

    /**
     * Las opciones para `<x-ui.segmentado>`.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function opciones(string $activa): array
    {
        $opciones = [];

        foreach (self::TODAS as $clave => $solapa) {
            $opciones[] = ['texto' => $solapa['texto'], 'url' => ($this->url)($clave), 'activa' => $clave === $activa];
        }

        return $opciones;
    }

    /** El icono de una solapa. */
    public static function icono(string $clave): string
    {
        return self::TODAS[$clave]['icono'] ?? 'bi-file-earmark';
    }
}
