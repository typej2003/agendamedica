<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\DocumentoDeRecipe;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;

/**
 * **La vista previa de lo que se imprime** (WEB-5.3a): una o varias hojas, cada una de un récipe (WEB-5.2) o de un documento
 * de la consulta —constancia, reposo, referencia o informe—, una detrás de otra en el orden pedido.
 *
 * Es la vista de la **página de impresión** cuando lo que se imprime puede ser de las dos familias (la secretaría imprime
 * «todos» los documentos de una cita). No reparte nada por su cuenta: cada documento lo reparte su propia clase
 * (`HojasDeRecipeVista` para los récipes, `HojasDeDocumentoVista` para el resto) y esta sólo las pone en orden y les agrega
 * la barra: el título, el resumen de la impresión y el enlace de vuelta. Tiene los mismos métodos que `HojasDeRecipeVista`,
 * que sigue siendo la vista de la página cuando se imprime un solo récipe.
 *
 * El diálogo de las opciones (modo y nombre del medicamento) sólo tiene sentido si hay récipes: `conOpciones()`.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**. Se prueba sin base de datos.
 */
final class HojasImpresasVista
{
    /**
     * @param  array<int,DocumentoDeRecipe|DocumentoImpreso>  $documentos  en el orden en que se imprimen
     * @param  array{fecha:string, hora:?string}|null  $proximaCita  la que sale al pie de los récipes
     * @param  array{volver:string, volverTexto:string}  $urls
     */
    public function __construct(
        private Membrete $membrete,
        private OpcionesDeRecipe $opciones,
        private array $documentos,
        private ?array $proximaCita,
        private array $urls,
        private OpcionesDeImpresionVista $dialogo,
    ) {
    }

    public function titulo(): string
    {
        $primero = $this->documentos[0] ?? null;

        if ($primero === null) {
            return 'Documentos';
        }

        $nombre = $primero->persona['nombre'];

        if (count($this->documentos) === 1) {
            return ($primero instanceof DocumentoImpreso ? $primero->nombre : $primero->titulo) . ' · ' . $nombre;
        }

        return ($this->soloRecipes() ? 'Récipes de ' : 'Documentos de ') . $nombre;
    }

    public function membrete(): Membrete
    {
        return $this->membrete;
    }

    public function dialogo(): OpcionesDeImpresionVista
    {
        return $this->dialogo;
    }

    /** ¿Hay récipes entre lo que se imprime? Sólo entonces se ofrece el botón «Opciones» de la hoja. */
    public function conOpciones(): bool
    {
        return $this->recipes() !== [];
    }

    /**
     * ¿Alguna hoja lleva **imágenes aparte**? Sólo entonces la barra ofrece «Incluir imágenes». Son las fotos de un eco
     * (WEB-11.13) y, desde A-13, los dibujos del cuello uterino de la colposcopía, que entran por el mismo camino.
     */
    public function conImagenes(): bool
    {
        foreach ($this->documentos as $documento) {
            if (! $documento instanceof DocumentoImpreso) {
                continue;
            }

            if ($documento->informe?->conImagenes() || $documento->conImagenesDeLaHoja()) {
                return true;
            }
        }

        return false;
    }

    /** El nombre histórico del método (WEB-11.13): hoy también devuelve `true` con los dibujos de la colposcopía. */
    public function conImagenesDeEco(): bool
    {
        return $this->conImagenes();
    }

    public function urlVolver(): string
    {
        return $this->urls['volver'];
    }

    public function textoVolver(): string
    {
        return $this->urls['volverTexto'];
    }

    public function resumenDeLasOpciones(): string
    {
        return $this->dialogo->resumen();
    }

    /**
     * Qué se va a imprimir, en una frase para la barra: cuántos récipes y medicamentos, cuántos documentos y cuántas hojas.
     * Con sólo récipes es el texto de siempre (`3 récipes · 4 medicamentos · 3 hojas`).
     */
    public function resumenDeLaImpresion(): string
    {
        $partes = [];

        $recipes = $this->recipes();
        if ($recipes !== []) {
            $medicamentos = array_sum(array_map(fn (DocumentoDeRecipe $recipe) => count($recipe->items), $recipes));
            $partes[] = count($recipes) === 1 ? 'Un récipe' : count($recipes) . ' récipes';
            $partes[] = $medicamentos . ($medicamentos === 1 ? ' medicamento' : ' medicamentos');
        }

        $otros = count($this->documentos) - count($recipes);
        if ($otros > 0) {
            $partes[] = $otros === 1 ? 'Un documento' : $otros . ' documentos';
        }

        $hojas = count($this->hojas());
        $partes[] = $hojas . ($hojas === 1 ? ' hoja' : ' hojas');

        return implode(' · ', $partes);
    }

    /**
     * Las hojas, en el orden en que salen de la impresora. Cada una trae su `tipo` (`recipe` o `documento`), que decide qué
     * parcial la pinta.
     *
     * @return array<int,array{tipo:string, etiqueta:?string, tamano:int, mitades:array<int,array<string,mixed>>}>
     */
    public function hojas(): array
    {
        $hojas = [];

        foreach ($this->documentos as $documento) {
            if ($documento instanceof DocumentoDeRecipe) {
                $delRecipe = (new HojasDeRecipeVista(
                    $this->membrete,
                    $this->opciones,
                    [$documento],
                    $this->proximaCita,
                    $this->urls,
                    $this->dialogo,
                ))->hojas();

                foreach ($delRecipe as $hoja) {
                    $hojas[] = ['tipo' => 'recipe'] + $hoja;
                }

                continue;
            }

            // Una eco se imprime a hoja completa (WEB-11.13): su informe y, si tiene imágenes, una segunda hoja.
            if ($documento->informe !== null) {
                $hojas[] = ['tipo' => 'informe', 'etiqueta' => null, 'tamano' => 10, 'mitades' => [], 'documento' => $documento, 'imagenes' => false];

                if ($documento->informe->conImagenes()) {
                    $hojas[] = ['tipo' => 'informe', 'etiqueta' => null, 'tamano' => 10, 'mitades' => [], 'documento' => $documento, 'imagenes' => true];
                }

                continue;
            }

            foreach ((new HojasDeDocumentoVista($this->membrete, [$documento]))->hojas() as $hoja) {
                $hojas[] = $hoja;
            }

            // Un documento en mitades con imágenes propias (los dibujos del cuello uterino de la colposcopía, A-13):
            // su texto sale como siempre y las imágenes en una hoja A4 vertical aparte, como la de un eco.
            if ($documento->conImagenesDeLaHoja()) {
                $hojas[] = ['tipo' => 'imagenes', 'documento' => $documento];
            }
        }

        return $hojas;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return array<int,DocumentoDeRecipe> */
    private function recipes(): array
    {
        return array_values(array_filter($this->documentos, fn ($documento) => $documento instanceof DocumentoDeRecipe));
    }

    private function soloRecipes(): bool
    {
        return count($this->recipes()) === count($this->documentos);
    }
}
