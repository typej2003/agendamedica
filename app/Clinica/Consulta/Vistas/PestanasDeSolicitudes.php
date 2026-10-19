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
 *
 * ## Qué pestañas se ofrecen
 *
 * `$ocultas` son las que la **especialidad** del contexto no ofrece, y las decide `AlcanceDeLaEspecialidad::pestanasDeSolicitudesOcultas()`
 * (una sola regla, como las solapas del examen). Hoy es una: en pediatría, **Dieta** —la dieta es el **ítem 9 del menú**,
 * *Alimentación*, con sección propia desde el 2026-10-19; tenerla además acá la mostraba dos veces (Alexander, O-41)—.
 * Una pestaña oculta tampoco se abre por URL: `normalizar()` la manda a la pestaña de siempre.
 */
final class PestanasDeSolicitudes
{
    public const LABORATORIO = 'laboratorio';

    public const ESTUDIOS = 'estudios';

    public const CONSTANCIA = 'constancia';

    public const REPOSO = 'reposo';

    public const REFERENCIAS = 'referencias';

    public const INFORME = 'informe';

    /**
     * **La historia clínica del paciente** (2026-10-19): la hoja que el escritorio muestra en vista previa dentro de
     * *Solicitudes* y que se imprime con su botón (`tabpage_15` de `tab_4`, `d_historia_clinica_dos`; en pediatría la
     * solapa se llama *Historia Pediátrica*, como en su build). Es de **sólo lectura**: no escribe nada.
     */
    public const HISTORIA = 'historia';

    public const HOSPITALIZACION = 'hospitalizacion';

    public const DIETA = 'dieta';

    /** La orden de laboratorio de la pareja (WEB-11.21): se abre con el botón «Pareja» del laboratorio y no es una pestaña de la fila. */
    public const PAREJA = 'pareja';

    /** La pestaña con la que se abre la sección. */
    public const POR_DEFECTO = self::CONSTANCIA;

    /**
     * Las pestañas en las que se **redacta un documento** (O-30): la constancia, el reposo, la referencia, el
     * informe y la dieta. Son las que ofrecen en el encabezado de la consulta el panel con la historia y el
     * resumen del paciente, porque salir de la consulta a buscarlo cuesta el trabajo sin guardar.
     *
     * El laboratorio, los estudios y la hospitalización **no** están: no son de la lista cerrada de la
     * observación (y el laboratorio y los estudios se cargan con el catálogo, no redactando un texto).
     */
    public const DE_DOCUMENTO = [self::CONSTANCIA, self::REPOSO, self::REFERENCIAS, self::INFORME, self::DIETA];

    /** ¿Esta pestaña redacta un documento? (La que no existe se normaliza antes, como en el resto de la sección.) */
    public static function esDeDocumento(?string $clave): bool
    {
        return in_array(self::normalizar($clave), self::DE_DOCUMENTO, true);
    }

    /** @var array<string,array{texto:string, icono:string}> */
    private const TODAS = [
        self::LABORATORIO => ['texto' => 'Laboratorio', 'icono' => 'bi-droplet'],
        self::ESTUDIOS => ['texto' => 'Estudios', 'icono' => 'bi-image'],
        self::CONSTANCIA => ['texto' => 'Constancia', 'icono' => 'bi-file-earmark-medical'],
        self::REPOSO => ['texto' => 'Reposo', 'icono' => 'bi-bandaid'],
        self::REFERENCIAS => ['texto' => 'Referencia', 'icono' => 'bi-person-up'],
        self::HOSPITALIZACION => ['texto' => 'Hospitalización', 'icono' => 'bi-hospital'],
        self::INFORME => ['texto' => 'Informe médico', 'icono' => 'bi-file-earmark-text'],
        // La historia va después del informe, como en el escritorio (y antes de *Imágenes*, que la web no tiene acá).
        self::HISTORIA => ['texto' => 'Historia clínica', 'icono' => 'bi-journal-medical'],
        self::DIETA => ['texto' => 'Dieta / Recomendaciones', 'icono' => 'bi-egg-fried'],
    ];

    /**
     * @param  Closure  $url  `fn(string $clave): string` — la URL de esa pestaña (la resuelve el controlador)
     */
    /**
     * @param  Closure(string):string  $url  arma la dirección de una pestaña
     * @param  array<int,string>  $ocultas  las pestañas que la especialidad no ofrece (ver `AlcanceDeLaEspecialidad::pestanasDeSolicitudesOcultas`)
     * @param  array<string,string>  $rotulos  el rótulo de una pestaña cuando la especialidad lo cambia (en pediatría,
     *                          la historia es la *Historia pediátrica*)
     */
    public function __construct(private Closure $url, private array $ocultas = [], private array $rotulos = [])
    {
    }

    /**
     * La clave de una pestaña que existe y se ofrece; cualquier otro valor de la URL es la pestaña por defecto. Una
     * pestaña **oculta** en esa especialidad también cae en la de siempre: su dirección no se puede usar para abrir
     * algo que el menú no ofrece (la dieta de pediatría es su ítem *Alimentación*, no una pestaña de Solicitudes).
     *
     * @param  array<int,string>  $ocultas
     */
    public static function normalizar(?string $clave, array $ocultas = []): string
    {
        if ($clave === null || ! (isset(self::TODAS[$clave]) || $clave === self::PAREJA)) {
            return self::POR_DEFECTO;
        }

        return in_array($clave, $ocultas, true) ? self::POR_DEFECTO : $clave;
    }

    /**
     * @param  array<int,string>  $ocultas
     * @return array<int,string> las claves de las pestañas que existen, sin las que la especialidad no ofrece
     */
    public static function claves(array $ocultas = []): array
    {
        return array_values(array_diff(array_keys(self::TODAS), $ocultas));
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
            // Las pestañas que la especialidad no ofrece no se pintan (en pediatría, *Dieta*: es su ítem *Alimentación*).
            if (in_array($clave, $this->ocultas, true)) {
                continue;
            }

            $opciones[] = ['texto' => $this->rotulos[$clave] ?? $pestana['texto'], 'url' => ($this->url)($clave), 'activa' => $clave === $activa];
        }

        return $opciones;
    }

    /** El icono de una pestaña. */
    public static function icono(string $clave): string
    {
        return self::TODAS[$clave]['icono'] ?? 'bi-file-earmark';
    }
}
