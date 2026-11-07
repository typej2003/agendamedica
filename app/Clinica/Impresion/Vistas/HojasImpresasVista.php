<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\DocumentoDeRecipe;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;

/**
 * **La vista previa de lo que se imprime** (WEB-5.3a): una o varias hojas, cada una de un récipe (WEB-5.2) o de un documento
 * de la consulta —constancia, reposo, referencia, informe, el informe de una eco o el del control de embarazo—, una detrás de
 * otra en el orden pedido.
 *
 * Cada documento trae **su modo de hoja** (O-26: `DocumentoImpreso::modo()`, que lo declara `ModoDeLaHoja`): media hoja
 * horizontal para los récipes y las solicitudes, Carta vertical completa para los informes de los ecos y el del control de
 * embarazo. Esta vista los pone en orden y les agrega la barra; el reparto de cada uno lo hace su propia clase
 * (`HojasDeRecipeVista`, `HojasDeDocumentoVista`, y el formulario de una eco). Tiene los mismos métodos que `HojasDeRecipeVista`,
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
     * @param  bool  $pediatria  si el contexto de impresión es pediatría (lo decide el controlador): cada hoja lleva la
     *                           cabecera del escritorio (sexo, representante, historia y edad compacta) y el récipe sus medidas
     * @param  array{documento:string, nombre:string, actual:string, opciones:array<int,array{valor:string,titulo:string,descripcion:string,activa:bool}>, urlGuardar:string}|null  $hojaDelImprimible
     *                           la hoja del **único** documento configurable que se imprime (2026-11-05), para el botón
     *                           de configuración y su diálogo; `null` si no hay uno solo
     */
    public function __construct(
        private Membrete $membrete,
        private OpcionesDeRecipe $opciones,
        private array $documentos,
        private ?array $proximaCita,
        private array $urls,
        private OpcionesDeImpresionVista $dialogo,
        private bool $pediatria = false,
        private ?array $hojaDelImprimible = null,
    ) {
    }

    /**
     * **La hoja del documento que se está imprimiendo** (2026-11-05): con el botón de configuración de la barra se elige
     * una de las tres hojas y se recuerda para ese imprimible. Sólo se ofrece cuando lo que se imprime es **un** documento
     * configurable: si son varios, cada uno tiene su hoja ya guardada y no hay una sola que cambiar.
     *
     * @return array{documento:string, nombre:string, actual:string, opciones:array<int,array{valor:string,titulo:string,descripcion:string,activa:bool}>, urlGuardar:string}|null
     */
    public function hojaDelDocumento(): ?array
    {
        return $this->hojaDelImprimible;
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
     * ¿Alguna hoja lleva **imágenes**? Sólo entonces la barra ofrece «Incluir imágenes». Son las fotos de un eco
     * (WEB-11.13), en una hoja aparte, y las de la **colposcopía y la histeroscopía** (A-13 y 2026-10-18), que van en la
     * misma página de su informe (`DocumentoImpreso::imagenes`).
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
        /** @var array<int,DocumentoImpreso> $documentos */
        $documentos = [];

        // Los documentos de texto se agrupan: `HojasDeDocumentoVista` reparte dos por página en la media hoja (el «estilo
        // récipe») y separa los que tienen hoja propia. Un récipe o un informe corta el grupo para no mezclarse.
        $volcar = function () use (&$hojas, &$documentos): void {
            if ($documentos === []) {
                return;
            }

            foreach ((new HojasDeDocumentoVista($this->membrete, $documentos, null, $this->pediatria))->hojas() as $hoja) {
                $hojas[] = $hoja;
            }

            $documentos = [];
        };

        foreach ($this->documentos as $documento) {
            if ($documento instanceof DocumentoDeRecipe) {
                $volcar();

                $delRecipe = (new HojasDeRecipeVista(
                    $this->membrete,
                    $this->opciones,
                    [$documento],
                    $this->proximaCita,
                    $this->urls,
                    $this->dialogo,
                    $this->pediatria,
                ))->hojas();

                foreach ($delRecipe as $hoja) {
                    $hojas[] = ['tipo' => 'recipe'] + $hoja;
                }

                continue;
            }

            // El **informe pre-operatorio** (O-47) es una hoja fija de dos páginas: no se reparte en mitades ni elige
            // página. Una sola entrada, y el parcial `_informe-preoperatorio` pinta las dos páginas.
            if ($documento->preoperatorio !== null) {
                $volcar();
                $hojas[] = ['tipo' => 'preoperatorio', 'etiqueta' => null, 'tamano' => 10, 'mitades' => [], 'documento' => $documento];

                continue;
            }

            // Una eco se imprime a hoja completa (WEB-11.13): su informe y, si tiene imágenes, una segunda hoja.
            if ($documento->informe !== null) {
                $volcar();
                $hojas[] = ['tipo' => 'informe', 'etiqueta' => null, 'tamano' => 10, 'mitades' => [], 'documento' => $documento, 'imagenes' => false];

                if ($documento->informe->conImagenes()) {
                    $hojas[] = ['tipo' => 'informe', 'etiqueta' => null, 'tamano' => 10, 'mitades' => [], 'documento' => $documento, 'imagenes' => true];
                }

                continue;
            }

            $documentos[] = $documento;
        }

        $volcar();

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
