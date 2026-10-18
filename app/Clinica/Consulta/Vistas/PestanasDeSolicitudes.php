<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;

/**
 * **Las pestañas de la sección Solicitudes** (WEB-5.3a, ítem 11 del panel de la consulta): Laboratorio · Estudios · Constancia ·
 * Reposo · Referencia · Hospitalización · Informe médico · Dieta / Recomendaciones (el orden de las pestañas del escritorio,
 * `w_consulta_principal.srw`, `tab_4`; Hospitalización y Dieta son de WEB-5.3b y Laboratorio y Estudios de WEB-5.4: el escritorio las tiene
 * primero —«Exámenes de laboratorio», «Rutinas de exámenes» y «Solicitud de estudios»— y la web junta las dos primeras en *Laboratorio*, porque
 * escriben las mismas filas). La pestaña con la que se abre la sección sigue siendo la Constancia.
 *
 * Son enlaces —cada pestaña es una URL de la misma sección (`?seccion=solicitudes&solicitud=reposo`)—, no
 * JavaScript, como las de los récipes (`PestanasDeRecipes`): funcionan sin script y la pestaña activa se puede
 * compartir y recargar.
 *
 * ## Cómo crece sin reescribirse
 *
 * `TODAS` es la lista de pestañas **que existen hoy**, y es el único lugar donde se agregan: una pestaña nueva suma su fila y su
 * parcial `_solicitudes-<clave>`. **Una pestaña que todavía no existe no se muestra**, ni vacía ni apagada: el panel de la
 * consulta ya dice qué llega con cada tarea. Sin base de datos.
 */
final class PestanasDeSolicitudes
{
    public const LABORATORIO = 'laboratorio';

    public const ESTUDIOS = 'estudios';

    public const CONSTANCIA = 'constancia';

    public const REPOSO = 'reposo';

    public const REFERENCIAS = 'referencias';

    public const INFORME = 'informe';

    public const HOSPITALIZACION = 'hospitalizacion';

    public const DIETA = 'dieta';

    /** La orden de laboratorio de la pareja (WEB-11.21): se abre con el botón «Pareja» del laboratorio y no es una pestaña de la fila. */
    public const PAREJA = 'pareja';

    /** La pestaña con la que se abre la sección. */
    public const POR_DEFECTO = self::CONSTANCIA;

    /** @var array<string,array{texto:string, icono:string}> */
    private const TODAS = [
        self::LABORATORIO => ['texto' => 'Laboratorio', 'icono' => 'bi-droplet'],
        self::ESTUDIOS => ['texto' => 'Estudios', 'icono' => 'bi-image'],
        self::CONSTANCIA => ['texto' => 'Constancia', 'icono' => 'bi-file-earmark-medical'],
        self::REPOSO => ['texto' => 'Reposo', 'icono' => 'bi-bandaid'],
        self::REFERENCIAS => ['texto' => 'Referencia', 'icono' => 'bi-person-up'],
        self::HOSPITALIZACION => ['texto' => 'Hospitalización', 'icono' => 'bi-hospital'],
        self::INFORME => ['texto' => 'Informe médico', 'icono' => 'bi-file-earmark-text'],
        self::DIETA => ['texto' => 'Dieta / Recomendaciones', 'icono' => 'bi-egg-fried'],
    ];

    /**
     * @param  Closure  $url  `fn(string $clave): string` — la URL de esa pestaña (la resuelve el controlador)
     */
    public function __construct(private Closure $url, private ?string $rotuloDeLaDieta = null)
    {
    }

    /** La clave de una pestaña que existe; cualquier otro valor de la URL es la pestaña por defecto. */
    public static function normalizar(?string $clave): string
    {
        return $clave !== null && (isset(self::TODAS[$clave]) || $clave === self::PAREJA) ? $clave : self::POR_DEFECTO;
    }

    /** @return array<int,string> las claves de las pestañas que existen */
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

        foreach (self::TODAS as $clave => $pestana) {
            $opciones[] = ['texto' => $clave === self::DIETA && $this->rotuloDeLaDieta !== null ? $this->rotuloDeLaDieta : $pestana['texto'], 'url' => ($this->url)($clave), 'activa' => $clave === $activa];
        }

        return $opciones;
    }

    /** El icono de una pestaña. */
    public static function icono(string $clave): string
    {
        return self::TODAS[$clave]['icono'] ?? 'bi-file-earmark';
    }
}
