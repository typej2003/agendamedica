<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **Riesgo quirúrgico** (cirugía pediátrica, 2026-10-19): la tercera solapa del ítem «I.Q.».
 *
 * Muestra el **catálogo** de factores del médico con una casilla por factor (los que ya están marcados al paciente
 * vienen tildados), los **puntos** que suman y el **resumen** de la consulta (clase, conclusión e intervención). El
 * mapa del dato está en `RiesgoQuirurgico`.
 */
final class RiesgoQuirurgicoVista
{
    /**
     * @param  array<int,array{titulo:string, factores:array<int,array{descripcion:string, puntos:int, marcado:bool}>}>  $grupos
     * @param  array<int,array{clave:string, etiqueta:string, clase:string, valor:string}>  $campos
     */
    public function __construct(
        private array $grupos,
        private array $campos,
        private int $puntos,
        private string $urlGuardar,
        private string $urlVolver = '',
    ) {
    }

    /** @return array<int,array{titulo:string, factores:array<int,array{descripcion:string, puntos:int, marcado:bool}>}> */
    public function grupos(): array
    {
        return $this->grupos;
    }

    /** @return array<int,array{clave:string, etiqueta:string, clase:string, valor:string}> */
    public function campos(): array
    {
        return $this->campos;
    }

    public function puntos(): int
    {
        return $this->puntos;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function tieneCatalogo(): bool
    {
        return $this->grupos !== [];
    }

    public function ayuda(): string
    {
        return 'Marcá los factores de riesgo del paciente: los puntos se suman solos y quedan en el resumen de la '
            . 'evaluación. Los factores son el catálogo de riesgo del médico; el paciente es el mismo en todas sus '
            . 'consultas, así que lo marcado se conserva de una consulta a la otra.';
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el riesgo quirúrgico. Si sales ahora, se pierden.';
    }
}
