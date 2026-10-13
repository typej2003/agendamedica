<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Clinica\Impresion\RecipeDeEjemplo;

/**
 * **La hoja de prueba** (WEB-8.2): el récipe de ejemplo con el formato **guardado** del médico, tal como lo imprimiría.
 *
 * No arma ninguna hoja por su cuenta: usa `HojasDeRecipeVista` —la misma que imprime el récipe de verdad (WEB-5.2)— con un
 * paciente y unos medicamentos de ejemplo, de modo que lo que se ve acá y lo que sale en un récipe real no pueden divergir.
 * Se muestra de dos maneras: **incrustada** en la pantalla del formato (sin la barra de la vista previa, dentro de un
 * `<iframe>`) o **completa** (con su barra y su botón de imprimir), que es lo que abre «Imprimir prueba».
 *
 * Sin base de datos: recibe el membrete y las opciones ya leídos.
 */
final class HojaDePruebaVista
{
    private HojasDeRecipeVista $hojas;

    public function __construct(
        Membrete $membrete,
        OpcionesDeRecipe $opciones,
        private bool $incrustada,
        private string $urlVolver,
    ) {
        $this->hojas = new HojasDeRecipeVista(
            $membrete,
            $opciones,
            [RecipeDeEjemplo::documento()],
            RecipeDeEjemplo::proximaCita(),
            ['volver' => $urlVolver, 'volverTexto' => 'Volver al formato de impresión'],
            // El diálogo de opciones no se ofrece en la hoja de prueba: las opciones del récipe se eligen al imprimir uno.
            new OpcionesDeImpresionVista($opciones, '', ''),
        );
    }

    public function titulo(): string
    {
        return 'Hoja de prueba';
    }

    /** ¿Va dentro del `<iframe>` de la pantalla del formato? Entonces no lleva barra. */
    public function incrustada(): bool
    {
        return $this->incrustada;
    }

    public function membrete(): Membrete
    {
        return $this->hojas->membrete();
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function textoVolver(): string
    {
        return 'Volver al formato de impresión';
    }

    /** "Datos de ejemplo · Un récipe · 3 medicamentos · 1 hoja · …": lo que dice la barra. */
    public function resumen(): string
    {
        return 'Datos de ejemplo · ' . $this->hojas->resumenDeLaImpresion() . ' · ' . $this->hojas->resumenDeLasOpciones();
    }

    /**
     * Las hojas del récipe de ejemplo, listas para pintar con `clinica.imprimir._recipe-mitad`.
     *
     * @return array<int,array{etiqueta:?string, tamano:int, mitades:array<int,array<string,mixed>>}>
     */
    public function hojas(): array
    {
        return $this->hojas->hojas();
    }
}
