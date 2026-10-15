<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;

/**
 * **Los tipos de estudio de la sección Estudio ecográfico** (WEB-6.1, ítem 5 del panel del legado).
 *
 * El ítem 5 del panel del legado son **siete** ventanas distintas —eco ginecológico, obstétrico de primero
 * y de segundo/tercer trimestre, doppler perinatal, mamarío, abdominal y ecocardiograma fetal—, cada una
 * con su formulario y su informe. En la web las siete viven en la **misma sección**
 * (`?seccion=estudio`), y `&tipo=` elige cuál: son enlaces, como las pestañas de las solicitudes
 * (`PestanasDeSolicitudes`), así que funcionan sin JavaScript y la que se está mirando se puede compartir
 * y recargar.
 *
 * ## Cómo crece sin reescribirse
 *
 * `TODOS` es la lista de tipos **que existen hoy**, y es el único lugar donde se agregan: un tipo nuevo
 * suma su fila, su mapa del dato, su ViewModel y su parcial `_estudio-<clave>`, sin tocar el contenedor
 * ni el controlador. **Un tipo que todavía no existe no se muestra**, ni apagado: el panel de la consulta
 * ya dijo qué llegaba con cada tarea. Hoy están el **eco pélvico** (WEB-6.1), el **eco obstétrico del primer
 * trimestre** y el del **2.º/3.er** (WEB-6.2), el **eco Doppler perinatal** y el **ecocardiograma fetal**
 * (WEB-6.3); los ultrasonidos mamarío y abdominal siguen en WEB-6.4.
 *
 * Sin base de datos.
 */
final class TiposDeEstudio
{
    /** El **eco pélvico o ginecológico**: el de WEB-6.1 (`w_cargar_visualizar_ecografia`). */
    public const GINECOLOGICO = 'ginecologico';

    /**
     * El **eco obstétrico del primer trimestre**: el de WEB-6.2 (`w_cargar_visualizar_ecografia_obstetri_1`,
     * con el DataWindow `d_eco_obstetrico_nueva`).
     */
    public const OBSTETRICO = 'obstetrico';

    /**
     * El **eco obstétrico del segundo/tercer trimestre** (WEB-6.2): la misma tabla y la misma pantalla que
     * el del tercero en el escritorio (`d_eco_obstetrico_trimestral_nueva`), con otro informe.
     */
    public const TERCERO = 'tercero';

    /**
     * El **eco Doppler perinatal** (WEB-6.3): la pestaña viva de la consulta
     * (`w_consulta_principal.srw:22650`, con el DataWindow `d_eco_doppler`, que vive en
     * `fuentes/ventana_consulta/` y no en `datawin_procesos/`: trampas 123 y 124).
     *
     * ⚠️ La clave va **sin guion** para no tocar el `where('tipo', '[a-z]+')` de la ruta (trampa 111).
     */
    public const DOPPLER = 'doppler';

    /**
     * El **ecocardiograma fetal** (WEB-6.3): la ventana
     * `w_cargar_visualizar_ecocardiograma_fetal` (`w_consulta_principal.srw:20477`), con el DataWindow
     * `d_ecocardiograma_fetal`, que vive en `fuentes/ventana_consulta/` y no en `datawin_procesos/` (trampas
     * 123 y 124).
     *
     * ⚠️ Su tabla (`ecocadiograma_fetal`) trae el **error de tipeo del legado**, sin la «r», y **no se
     * renombra** (regla 2 de `AGENTS.md`). No confundir con la ecocardiografía adulta, que es de cardiología.
     * La clave va **sin guion** para no tocar el `where('tipo', '[a-z]+')` de la ruta (trampa 111).
     */
    public const FETAL = 'fetal';

    /** El tipo con el que se abre la sección. */
    public const POR_DEFECTO = self::GINECOLOGICO;

    /** @var array<string,array{texto:string, icono:string}> */
    private const TODOS = [
        self::GINECOLOGICO => ['texto' => 'Eco ginecológico', 'icono' => 'bi-activity'],
        self::OBSTETRICO => ['texto' => 'Eco obstétrico (1.er trimestre)', 'icono' => 'bi-heart-pulse'],
        self::TERCERO => ['texto' => 'Eco obstétrico (2.º y 3.er trimestre)', 'icono' => 'bi-person-arms-up'],
        self::DOPPLER => ['texto' => 'Eco Doppler perinatal', 'icono' => 'bi-soundwave'],
        self::FETAL => ['texto' => 'Ecocardiograma fetal', 'icono' => 'bi-heart'],
    ];

    /**
     * @param  Closure  $url  `fn(string $clave): string` — la URL de ese tipo (la resuelve el controlador)
     */
    public function __construct(private Closure $url)
    {
    }

    /** La clave de un tipo que existe; cualquier otro valor de la URL es el tipo por defecto. */
    public static function normalizar(?string $clave): string
    {
        return $clave !== null && isset(self::TODOS[$clave]) ? $clave : self::POR_DEFECTO;
    }

    /**
     * ¿La clave es un tipo que existe? Es lo que distingue "el tipo por defecto" de "un tipo que no
     * existe": para **leer** un valor raro se cae al que hay (como las pestañas de las solicitudes), pero
     * para **escribir** no se puede guardar en un estudio que no existe (404).
     */
    public static function existe(?string $clave): bool
    {
        return $clave !== null && isset(self::TODOS[$clave]);
    }

    /** @return array<int,string> las claves de los tipos que existen */
    public static function claves(): array
    {
        return array_keys(self::TODOS);
    }

    /**
     * Las opciones para `<x-ui.segmentado>`.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function opciones(string $activa): array
    {
        $opciones = [];

        foreach (self::TODOS as $clave => $tipo) {
            $opciones[] = ['texto' => $tipo['texto'], 'url' => ($this->url)($clave), 'activa' => $clave === $activa];
        }

        return $opciones;
    }

    /** El rótulo de un tipo (`Eco ginecológico`). */
    public static function texto(string $clave): string
    {
        return self::TODOS[$clave]['texto'] ?? 'Estudio';
    }

    /** El icono de un tipo. */
    public static function icono(string $clave): string
    {
        return self::TODOS[$clave]['icono'] ?? 'bi-activity';
    }
}
