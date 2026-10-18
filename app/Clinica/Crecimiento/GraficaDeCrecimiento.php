<?php

namespace App\Clinica\Crecimiento;

/**
 * **La geometría de una gráfica de crecimiento** (WEB-7.4.3c), lista para pintarla en un `<svg>` propio, sin librería: las tres curvas de
 * la referencia (baja, media y alta), la franja entre la baja y la alta, los puntos del niño unidos por una línea y los ejes con sus marcas.
 * Es pura: recibe números y devuelve coordenadas del `viewBox` (640 × 380). Nada de HTML ni de estilos: la vista pone las clases.
 *
 * El eje de las x es una **edad en años** (o la talla, en la gráfica de peso para la talla). Si no hay curvas —el consultorio no cargó la
 * referencia— la gráfica igual se dibuja con los puntos del niño.
 */
final class GraficaDeCrecimiento
{
    public const ANCHO = 640;
    public const ALTO = 380;

    private const IZQUIERDA = 56;
    private const DERECHA = 16;
    private const ARRIBA = 16;
    private const ABAJO = 46;

    private float $xMin;
    private float $xMax;
    private float $yMin;
    private float $yMax;

    /** @var array<int,float> */
    private array $marcasX;

    /** @var array<int,float> */
    private array $marcasY;

    /**
     * @param  array{b:array<int,array{x:float, y:float}>, m:array<int,array{x:float, y:float}>, a:array<int,array{x:float, y:float}>}  $curvas
     *         cada curva con sus puntos (en la gráfica de peso para la talla cada una tiene sus propias tallas)
     * @param  array<int,array{x:float, y:float, etiqueta:string}>  $puntos  los del niño, ordenados por fecha
     * @param  array{0:float,1:float}|null  $dominioX  el rango fijo del eje x (el juego de edades); `null` = el de los datos
     * @param  array<int,float>|null  $marcasX  las marcas del eje x; `null` = automáticas
     */
    public function __construct(
        private string $titulo,
        private string $ejeX,
        private string $ejeY,
        private array $curvas,
        private array $puntos,
        ?array $dominioX = null,
        ?array $marcasX = null,
    ) {
        $xs = array_merge(array_column($this->curvas['b'], 'x'), array_column($this->curvas['m'], 'x'), array_column($this->curvas['a'], 'x'), array_column($this->puntos, 'x'));
        $ys = array_merge(array_column($this->curvas['b'], 'y'), array_column($this->curvas['m'], 'y'), array_column($this->curvas['a'], 'y'), array_column($this->puntos, 'y'));

        if ($dominioX !== null) {
            [$this->xMin, $this->xMax] = $dominioX;
            $this->marcasX = $marcasX ?? EjesDeLaGrafica::marcas($this->xMin, $this->xMax)['marcas'];
        } elseif ($xs !== []) {
            $ejes = EjesDeLaGrafica::marcas(min($xs), max($xs));
            [$this->xMin, $this->xMax, $this->marcasX] = [$ejes['min'], $ejes['max'], $ejes['marcas']];
        } else {
            [$this->xMin, $this->xMax, $this->marcasX] = [0.0, 1.0, [0.0, 1.0]];
        }

        $ejes = $ys === [] ? ['min' => 0.0, 'max' => 1.0, 'marcas' => [0.0, 1.0]] : EjesDeLaGrafica::marcas(min($ys), max($ys));
        [$this->yMin, $this->yMax, $this->marcasY] = [$ejes['min'], $ejes['max'], $ejes['marcas']];
    }

    public function titulo(): string
    {
        return $this->titulo;
    }

    public function ejeX(): string
    {
        return $this->ejeX;
    }

    public function ejeY(): string
    {
        return $this->ejeY;
    }

    public function viewBox(): string
    {
        return '0 0 ' . self::ANCHO . ' ' . self::ALTO;
    }

    public function conCurvas(): bool
    {
        return count($this->curvas['m']) >= 2;
    }

    public function conPuntos(): bool
    {
        return $this->puntos !== [];
    }

    /** Los rectángulos del área de dibujo (para el marco). @return array{x:float, y:float, ancho:float, alto:float} */
    public function marco(): array
    {
        return [
            'x' => (float) self::IZQUIERDA,
            'y' => (float) self::ARRIBA,
            'ancho' => (float) (self::ANCHO - self::IZQUIERDA - self::DERECHA),
            'alto' => (float) (self::ALTO - self::ARRIBA - self::ABAJO),
        ];
    }

    /** @return array<int,array{pos:float, texto:string}> las marcas del eje x con su posición en el `viewBox` */
    public function marcasDelEjeX(): array
    {
        $salida = [];
        foreach ($this->marcasX as $marca) {
            if ($marca >= $this->xMin - 1e-9 && $marca <= $this->xMax + 1e-9) {
                $salida[] = ['pos' => $this->px($marca), 'texto' => self::numero($marca)];
            }
        }

        return $salida;
    }

    /** @return array<int,array{pos:float, texto:string}> las marcas del eje y */
    public function marcasDelEjeY(): array
    {
        return array_map(fn (float $marca) => ['pos' => $this->py($marca), 'texto' => self::numero($marca)], $this->marcasY);
    }

    /** Los puntos `x,y x,y …` de una curva (`b`, `m` o `a`) para un `<polyline>`. */
    public function polilinea(string $curva): string
    {
        return implode(' ', array_map(fn ($p) => $this->px($p['x']) . ',' . $this->py($p['y']), $this->curvas[$curva]));
    }

    /** El polígono de la franja entre la curva baja y la alta, para un `<polygon>`. */
    public function franja(): string
    {
        $arriba = array_map(fn ($p) => $this->px($p['x']) . ',' . $this->py($p['y']), $this->curvas['a']);
        $abajo = array_map(fn ($p) => $this->px($p['x']) . ',' . $this->py($p['y']), array_reverse($this->curvas['b']));

        return implode(' ', array_merge($arriba, $abajo));
    }

    /** La línea que une los puntos del niño. */
    public function lineaDelNino(): string
    {
        return implode(' ', array_map(fn ($p) => $this->px($p['x']) . ',' . $this->py($p['y']), $this->puntos));
    }

    /** @return array<int,array{cx:float, cy:float, etiqueta:string}> */
    public function puntosDelNino(): array
    {
        return array_map(fn ($p) => ['cx' => $this->px($p['x']), 'cy' => $this->py($p['y']), 'etiqueta' => $p['etiqueta']], $this->puntos);
    }

    /** Los puntos de la curva `b|m|a` que se rotulan al final de la línea: su posición y su letra. @return array{x:float, y:float}|null */
    public function finDeLaCurva(string $curva): ?array
    {
        $puntos = $this->curvas[$curva];
        $ultimo = end($puntos);

        return $ultimo === false ? null : ['x' => $this->px($ultimo['x']), 'y' => $this->py($ultimo['y'])];
    }

    private function px(float $x): float
    {
        $ancho = self::ANCHO - self::IZQUIERDA - self::DERECHA;
        $rango = $this->xMax - $this->xMin;

        return round(self::IZQUIERDA + ($rango > 0 ? ($x - $this->xMin) / $rango : 0.5) * $ancho, 1);
    }

    private function py(float $y): float
    {
        $alto = self::ALTO - self::ARRIBA - self::ABAJO;
        $rango = $this->yMax - $this->yMin;

        return round(self::ALTO - self::ABAJO - ($rango > 0 ? ($y - $this->yMin) / $rango : 0.5) * $alto, 1);
    }

    /** Un número de marca en español: sin ceros de más y con coma decimal. */
    public static function numero(float $valor): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.'));
    }
}
