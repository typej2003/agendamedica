<?php

namespace App\Clinica\Impresion;

/**
 * **El informe de una ecografía a hoja completa** (WEB-11.13): el formulario del estudio **lleno**, con todas las opciones y la elegida
 * marcada, como lo imprime el escritorio (Alexander, 2026-10-15), más una **hoja aparte con las imágenes** del estudio.
 *
 * Es un objeto de valores ya resuelto, sin base de datos: lo arma `ArmadorDeInformeDeEco` desde el mapa del dato del estudio y lo pinta
 * `clinica/imprimir/_informe-eco`. Un `DocumentoImpreso` que lo trae se imprime en una hoja A4 vertical completa (no en mitades).
 *
 * @phpstan-type Campo array{etiqueta:string, tipo:string, valor:?string, unidad:?string, opciones:array<int,array{texto:string, marcada:bool}>}
 */
final class InformeDeEco
{
    /**
     * @param  array<int,array{etiqueta:string, valor:string}>  $encabezado  gesta, FUR, FPP, EG…: la franja de arriba
     * @param  array<int,array{titulo:string, campos:array<int,array<string,mixed>>, grilla:?array{ejes:array<int,string>, filas:array<int,array{etiqueta:string, celdas:array<int,?string>}>}}>  $bloques
     * @param  array<int,array{src:string, descripcion:?string}>  $imagenes  las imágenes del estudio, ya listas para la hoja aparte
     */
    public function __construct(
        public readonly array $encabezado,
        public readonly array $bloques,
        public readonly array $imagenes = [],
    ) {
    }

    /** ¿Hay imágenes para la segunda hoja? */
    public function conImagenes(): bool
    {
        return $this->imagenes !== [];
    }

    /** Lo mismo con las imágenes puestas (las trae quien arma el documento). */
    public function conLasImagenes(array $imagenes): self
    {
        return new self($this->encabezado, $this->bloques, $imagenes);
    }
}
