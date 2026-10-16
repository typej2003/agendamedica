<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;

/**
 * **El chip «Imágenes de mamas» del examen** (WEB-11.10): dos lienzos, uno por mama, sobre la imagen base, con color y grosor.
 * Cada consulta tiene sus dibujos; no se imprimen (Alexander). Sin base de datos: recibe qué lados ya tienen dibujo.
 */
final class DibujosDeMamasVista
{
    /**
     * @param  Closure(string):string  $urlDibujo  la dirección para leer/guardar/quitar el dibujo de un lado
     * @param  Closure(string):string  $urlGuardar  lo mismo para guardar (misma ruta con otro verbo; se separa para claridad)
     * @param  array<int,string>  $ladosConDibujo  los lados (`derecha`, `izquierda`) que ya tienen trazo guardado
     */
    public function __construct(
        private Closure $urlDibujo,
        private Closure $urlGuardar,
        private array $ladosConDibujo = [],
    ) {
    }

    /**
     * Los dos lienzos, **como se ven de frente**: a la izquierda de la pantalla la mama derecha de la paciente (como el escritorio).
     *
     * @return array<int,array{lado:string, titulo:string, base:string, dibujo:?string, guardar:string}>
     */
    public function lienzos(): array
    {
        return array_map(fn (string $lado) => [
            'lado' => $lado,
            'titulo' => 'Mama ' . $lado,
            'base' => asset('img/clinica/mama-' . $lado . '.svg'),
            'dibujo' => in_array($lado, $this->ladosConDibujo, true) ? ($this->urlDibujo)($lado) : null,
            'guardar' => ($this->urlGuardar)($lado),
        ], ['derecha', 'izquierda']);
    }

    public function colores(): array
    {
        return ['#d62828' => 'Rojo', '#1d4ed8' => 'Azul', '#15803d' => 'Verde', '#000000' => 'Negro', '#f59e0b' => 'Naranja'];
    }

    public function ayuda(): string
    {
        return 'Marca con color la zona de la lesión o anomalía sobre cada mama. El dibujo es de esta consulta y se guarda solo al soltar el trazo; no se imprime.';
    }
}
