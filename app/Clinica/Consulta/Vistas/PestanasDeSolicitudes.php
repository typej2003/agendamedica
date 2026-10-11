<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;

/**
 * **Las pestañas de la sección Solicitudes** (WEB-5.3a, ítem 11 del panel de la consulta): Constancia · Reposo ·
 * Referencia · Informe.
 *
 * Son enlaces —cada pestaña es una URL de la misma sección (`?seccion=solicitudes&solicitud=reposo`)—, no
 * JavaScript, como las de los récipes (`PestanasDeRecipes`): funcionan sin script y la pestaña activa se puede
 * compartir y recargar.
 *
 * ## Cómo crece sin reescribirse
 *
 * `TODAS` es la lista de pestañas **que existen hoy**, y es el único lugar donde se agregan: las entregas que siguen
 * (hospitalización y dietas, WEB-5.3b; laboratorio y estudios, WEB-5.4) suman su fila y su parcial
 * `_solicitudes-<clave>`. **Una pestaña que todavía no existe no se muestra**, ni vacía ni apagada: el panel de la
 * consulta ya dice qué llega con cada tarea. Sin base de datos.
 */
final class PestanasDeSolicitudes
{
    public const CONSTANCIA = 'constancia';

    public const REPOSO = 'reposo';

    public const REFERENCIAS = 'referencias';

    public const INFORME = 'informe';

    /** La pestaña con la que se abre la sección. */
    public const POR_DEFECTO = self::CONSTANCIA;

    /** @var array<string,array{texto:string, icono:string}> */
    private const TODAS = [
        self::CONSTANCIA => ['texto' => 'Constancia', 'icono' => 'bi-file-earmark-medical'],
        self::REPOSO => ['texto' => 'Reposo', 'icono' => 'bi-bandaid'],
        self::REFERENCIAS => ['texto' => 'Referencia', 'icono' => 'bi-person-up'],
        self::INFORME => ['texto' => 'Informe médico', 'icono' => 'bi-file-earmark-text'],
    ];

    /**
     * @param  Closure  $url  `fn(string $clave): string` — la URL de esa pestaña (la resuelve el controlador)
     */
    public function __construct(private Closure $url)
    {
    }

    /** La clave de una pestaña que existe; cualquier otro valor de la URL es la pestaña por defecto. */
    public static function normalizar(?string $clave): string
    {
        return $clave !== null && isset(self::TODAS[$clave]) ? $clave : self::POR_DEFECTO;
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
            $opciones[] = ['texto' => $pestana['texto'], 'url' => ($this->url)($clave), 'activa' => $clave === $activa];
        }

        return $opciones;
    }

    /** El icono de una pestaña. */
    public static function icono(string $clave): string
    {
        return self::TODAS[$clave]['icono'] ?? 'bi-file-earmark';
    }
}
