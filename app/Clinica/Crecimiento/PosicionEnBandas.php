<?php

namespace App\Clinica\Crecimiento;

/**
 * **Dónde cae una medida respecto a las tres curvas** de la referencia (baja / media / alta) del escritorio de pediatría (WEB-7.4.3b).
 *
 * `banda` es la curva más cercana (`b`, `m` o `a`) y `zona` el tramo en que está: por debajo de la baja, entre la baja y la media, entre la
 * media y la alta, o por encima de la alta. **No se dice qué percentil es cada curva** (pregunta 2 abierta): se etiqueta «baja / media /
 * alta» sin inventar un número.
 */
final class PosicionEnBandas
{
    public const DEBAJO = 'debajo';
    public const BAJO_MEDIO = 'bajo-medio';
    public const MEDIO_ALTO = 'medio-alto';
    public const ENCIMA = 'encima';

    private const TEXTOS = [
        self::DEBAJO => 'Por debajo de la curva baja',
        self::BAJO_MEDIO => 'Entre la curva baja y la media',
        self::MEDIO_ALTO => 'Entre la curva media y la alta',
        self::ENCIMA => 'Por encima de la curva alta',
    ];

    /**
     * @param  array{b:float, m:float, a:float}  $curvas  los valores de las tres curvas a esa edad
     */
    public function __construct(
        public readonly string $medida,
        public readonly float $valor,
        public readonly string $banda,
        public readonly string $zona,
        public readonly array $curvas,
    ) {
    }

    public function texto(): string
    {
        return self::TEXTOS[$this->zona];
    }

    /** La posición en pocas palabras, para una celda de tabla. */
    public function corto(): string
    {
        return match ($this->zona) {
            self::DEBAJO => 'Debajo de la baja',
            self::BAJO_MEDIO => 'Baja–media',
            self::MEDIO_ALTO => 'Media–alta',
            default => 'Sobre la alta',
        };
    }

    /** ¿Está dentro de las curvas (entre la baja y la alta)? */
    public function enRango(): bool
    {
        return $this->zona === self::BAJO_MEDIO || $this->zona === self::MEDIO_ALTO;
    }
}
