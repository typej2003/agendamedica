<?php

namespace App\Clinica\Consulta;

use App\Models\DibujoDeColposcopia;

/**
 * **La imagen base del cuello uterino** (A-13): cuál es el archivo de cada variante y de cada base, y cómo se llama
 * cada una. Es la única fuente de esos textos y de esas rutas: la usan la tarjeta del lienzo
 * (`Vistas\DibujosDeColposcopiaVista`) y la hoja impresa (`Impresion\DibujosParaElInforme`), y así la base que se
 * elige en pantalla es exactamente la que sale impresa.
 *
 * Las cuatro imágenes ya estaban en el repositorio (las dejó la tarea A-13): dos vectoriales y dos del escritorio,
 * las cuatro en el mismo marco de 520×400 que las mamas. Sin estado y sin base de datos.
 */
final class ImagenDeColposcopia
{
    /** El `alt` del esquema y la etiqueta de la variante. */
    private const VARIANTES = [
        DibujoDeColposcopia::NULIPARA => [
            'titulo' => 'Cuello nulípara',
            'descripcion' => 'Orificio transverso',
        ],
        DibujoDeColposcopia::MULTIPARA => [
            'titulo' => 'Cuello multípara',
            'descripcion' => 'Orificio redondo',
        ],
    ];

    /** La etiqueta de cada base, en el orden en que se ofrecen (la vectorial primero: es la que se ve por defecto). */
    private const BASES = [
        DibujoDeColposcopia::VECTOR => 'Vectorial',
        DibujoDeColposcopia::ESCRITORIO => 'Del escritorio',
    ];

    public static function titulo(string $variante): string
    {
        return self::VARIANTES[$variante]['titulo'] ?? 'Cuello uterino';
    }

    public static function descripcion(string $variante): string
    {
        return self::VARIANTES[$variante]['descripcion'] ?? '';
    }

    /** El rótulo de la variante con su forma del orificio: `Cuello nulípara (orificio transverso)`. */
    public static function etiqueta(string $variante): string
    {
        $descripcion = self::descripcion($variante);

        return self::titulo($variante) . ($descripcion === '' ? '' : ' (' . mb_strtolower($descripcion) . ')');
    }

    /** El rótulo de una base (`Vectorial`, `Del escritorio`). */
    public static function nombreDeLaBase(string $base): string
    {
        return self::BASES[$base] ?? self::BASES[DibujoDeColposcopia::VECTOR];
    }

    /**
     * La base normalizada: la que se pidió si existe y, si no, la de por defecto. Es lo que lee la pantalla de una
     * fila guardada con un valor que no conocemos.
     */
    public static function base(?string $base): string
    {
        return in_array($base, DibujoDeColposcopia::BASES, true) ? $base : DibujoDeColposcopia::BASE_POR_DEFECTO;
    }

    /**
     * La ruta **relativa a `public/`** del archivo de esa variante y esa base: la vectorial es un SVG y la del
     * escritorio, el PNG escalado.
     */
    public static function ruta(string $variante, string $base): string
    {
        return 'img/clinica/colposcopia-' . $variante . '.' . (self::base($base) === DibujoDeColposcopia::ESCRITORIO ? 'png' : 'svg');
    }

    /** @return array<string,string> las bases ofrecidas, `valor => rótulo`, en su orden */
    public static function bases(): array
    {
        return self::BASES;
    }
}
