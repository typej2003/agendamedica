<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\ImagenDeColposcopia;
use App\Models\DibujoDeColposcopia;
use Closure;

/**
 * **La tarjeta «Dibujo del cuello uterino» de la colposcopía** (A-13): dos lienzos —la variante **nulípara** y la
 * **multípara**, las dos que traía la imagen del escritorio— con su **imagen base** elegible (vectorial o del
 * escritorio), el trazo guardado y las herramientas (color y grosor).
 *
 * Cada lienzo recuerda su base y su trazo **por consulta**: la pantalla recibe lo ya guardado y no consulta la base.
 * La base y el dibujo viajan en la misma fila (`dibujos_de_colposcopia`), así que cambiar la base no pierde el trazo.
 *
 * Sin base de datos: recibe las clausuras que arman las URLs y el estado ya leído.
 */
final class DibujosDeColposcopiaVista
{
    /**
     * @param  Closure(string):string  $urlDibujo  la dirección para leer el trazo de una variante
     * @param  Closure(string):string  $urlGuardar  la dirección para guardar/quitar el trazo y la base (misma ruta, otro verbo)
     * @param  array<string,array{base:string, dibujo:bool}>  $guardados  el estado por variante, ya leído
     */
    public function __construct(
        private Closure $urlDibujo,
        private Closure $urlGuardar,
        private array $guardados = [],
    ) {
    }

    /**
     * Los dos lienzos, en el orden en que los traía la imagen del escritorio: primero la nulípara y después la
     * multípara.
     *
     * @return array<int,array{variante:string, titulo:string, descripcion:string, base:string, bases:array<int,array{valor:string, texto:string, imagen:string}>, imagen:string, dibujo:?string, guardar:string}>
     */
    public function lienzos(): array
    {
        return array_map(function (string $variante): array {
            $estado = $this->guardados[$variante] ?? [];
            $base = ImagenDeColposcopia::base($estado['base'] ?? null);
            $tieneDibujo = (bool) ($estado['dibujo'] ?? false);

            return [
                'variante' => $variante,
                'titulo' => ImagenDeColposcopia::titulo($variante),
                'descripcion' => ImagenDeColposcopia::descripcion($variante),
                'base' => $base,
                'bases' => $this->bases($variante, $base),
                'imagen' => asset(ImagenDeColposcopia::ruta($variante, $base)),
                'dibujo' => $tieneDibujo ? ($this->urlDibujo)($variante) : null,
                'guardar' => ($this->urlGuardar)($variante),
            ];
        }, DibujoDeColposcopia::VARIANTES);
    }

    /**
     * Las opciones del selector de imagen base, con la elegida marcada y la URL de cada una (el JS la cambia sin
     * recargar).
     *
     * @return array<int,array{valor:string, texto:string, imagen:string}>
     */
    private function bases(string $variante, string $elegida): array
    {
        $opciones = [];

        foreach (ImagenDeColposcopia::bases() as $valor => $texto) {
            $opciones[] = [
                'valor' => $valor,
                'texto' => $texto,
                'imagen' => asset(ImagenDeColposcopia::ruta($variante, $valor)),
                'elegida' => $valor === $elegida,
            ];
        }

        return $opciones;
    }

    /** @return array<string,string> `#hex => nombre`, como los de las mamas. */
    public function colores(): array
    {
        return ['#d62828' => 'Rojo', '#1d4ed8' => 'Azul', '#15803d' => 'Verde', '#000000' => 'Negro', '#f59e0b' => 'Naranja'];
    }

    public function ayuda(): string
    {
        return 'Marca con color los hallazgos sobre la imagen del cuello uterino, en la variante que corresponda. Elige la '
            . 'imagen base (la vectorial o la del escritorio) y el dibujo no se pierde al cambiarla: se guarda solo, como un '
            . 'trazo transparente, y se imprime con el informe cuando marcas «Incluir imágenes».';
    }
}
