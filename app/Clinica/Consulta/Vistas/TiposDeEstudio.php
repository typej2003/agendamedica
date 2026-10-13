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
 * ya dijo qué llegaba con cada tarea. Hoy el único es el eco pélvico, que es WEB-6.1 (el obstétrico es
 * WEB-6.2, el doppler y el ecocardiograma WEB-6.3 y los ultrasonidos WEB-6.4).
 *
 * Sin base de datos.
 */
final class TiposDeEstudio
{
    /** El **eco pélvico o ginecológico**: el de WEB-6.1 (`w_cargar_visualizar_ecografia`). */
    public const GINECOLOGICO = 'ginecologico';

    /** El tipo con el que se abre la sección. */
    public const POR_DEFECTO = self::GINECOLOGICO;

    /** @var array<string,array{texto:string, icono:string}> */
    private const TODOS = [
        self::GINECOLOGICO => ['texto' => 'Eco ginecológico', 'icono' => 'bi-activity'],
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
