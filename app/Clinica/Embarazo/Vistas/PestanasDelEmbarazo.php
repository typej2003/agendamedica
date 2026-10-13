<?php

namespace App\Clinica\Embarazo\Vistas;

use Closure;

/**
 * **Las pestañas del control de embarazo** (WEB-6.6, ítem 6 del panel de la consulta): *Prenatal*,
 * *Laboratorio*, *Controles* y *Reporte*, que son las cuatro del escritorio (wiki `04-historia-clinica.md`).
 *
 * Son enlaces —cada pestaña es una URL de la misma sección (`?seccion=embarazo&pestana=laboratorio`)—, como
 * las de las solicitudes y las del estudio ecográfico: funcionan sin JavaScript y la que se está mirando se
 * puede compartir y recargar.
 *
 * `TODAS` es la lista de pestañas **que existen hoy**, y es el único lugar donde se agregan: una pestaña
 * nueva suma su fila, su ViewModel y su parcial `_embarazo-<clave>`. **Una pestaña que todavía no existe no
 * se muestra**, ni vacía ni apagada: el panel de la consulta ya dijo qué llega con cada tarea. Hoy existe la
 * primera —*Prenatal*, las gestas—; el laboratorio, los controles y el reporte llegan con el resto de esta
 * misma tarea y de la impresión.
 */
final class PestanasDelEmbarazo
{
    /** Las **gestas**: la FUR, la menarquia, el tipiaje y las dos cuentas (EG y FPP). */
    public const PRENATAL = 'prenatal';

    /** El **laboratorio** de la gesta: una fila por fecha (`prena_exames_b`). */
    public const LABORATORIO = 'laboratorio';

    /** Los **controles** de la gesta: una fila por fecha (`pre_natal_desarrollo_fino`). */
    public const CONTROLES = 'controles';

    /** La pestaña con la que se abre la sección. */
    public const POR_DEFECTO = self::PRENATAL;

    /** @var array<string,array{texto:string, icono:string}> */
    private const TODAS = [
        self::PRENATAL => ['texto' => 'Prenatal', 'icono' => 'bi-calendar-heart'],
        self::LABORATORIO => ['texto' => 'Laboratorio', 'icono' => 'bi-droplet-half'],
        self::CONTROLES => ['texto' => 'Controles', 'icono' => 'bi-graph-up'],
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
}
