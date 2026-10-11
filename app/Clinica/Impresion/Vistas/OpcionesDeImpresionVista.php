<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Models\RecipeFormato;

/**
 * **El diálogo "Imprimir récipe"** (WEB-5.2): las dos opciones de la hoja —qué se imprime y con qué nombre sale
 * el medicamento—, con lo que quedó guardado marcado, y a dónde se manda la elección.
 *
 * Lo comparten la sección de récipes de la consulta, la lista de récipes de una cita (la secretaría) y la vista
 * previa: es **el mismo formulario** en los tres lugares, porque la elección es del médico y la hacen igual quien
 * imprime desde la consulta y quien imprime desde la agenda. Sin base de datos.
 */
final class OpcionesDeImpresionVista
{
    public function __construct(
        private OpcionesDeRecipe $opciones,
        private string $urlGuardar,
        private string $siguiente = '',
    ) {
    }

    /** A dónde se manda la elección (`POST`): la guarda y sigue a la vista previa. */
    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    /**
     * La vista previa a la que se sigue después de guardar. En la sección de récipes el botón de cada récipe
     * lo completa al abrir el diálogo (hay **un** diálogo para toda la página); en la vista previa ya es ella misma.
     */
    public function siguiente(): string
    {
        return $this->siguiente;
    }

    /** @return array<int,array{valor:string, texto:string, ayuda:string, marcada:bool}> */
    public function modos(): array
    {
        return [
            [
                'valor' => RecipeFormato::MODO_RP_E_INDICACIONES,
                'texto' => 'Rp. + Indicaciones',
                'ayuda' => 'Una hoja partida en dos: Rp. para la farmacia y las indicaciones para el paciente.',
                'marcada' => $this->opciones->modo === RecipeFormato::MODO_RP_E_INDICACIONES,
            ],
            [
                'valor' => RecipeFormato::MODO_SOLO_INDICACIONES,
                'texto' => 'Solo indicaciones',
                'ayuda' => 'Media hoja, solo con las indicaciones para el paciente.',
                'marcada' => $this->opciones->modo === RecipeFormato::MODO_SOLO_INDICACIONES,
            ],
        ];
    }

    /** @return array<int,array{valor:string, texto:string, ayuda:string, marcada:bool}> */
    public function nombres(): array
    {
        return [
            [
                'valor' => RecipeFormato::NOMBRE_COMERCIAL,
                'texto' => 'Comercial',
                'ayuda' => 'El nombre de marca del vademécum.',
                'marcada' => $this->opciones->nombre === RecipeFormato::NOMBRE_COMERCIAL,
            ],
            [
                'valor' => RecipeFormato::NOMBRE_GENERICO,
                'texto' => 'Genérico',
                'ayuda' => 'El principio activo. Si falta, sale el comercial.',
                'marcada' => $this->opciones->nombre === RecipeFormato::NOMBRE_GENERICO,
            ],
        ];
    }

    /** Las opciones en una frase, para la barra de la vista previa. */
    public function resumen(): string
    {
        return ($this->opciones->conRpEIndicaciones() ? 'Rp. + Indicaciones' : 'Solo indicaciones')
            . ' · nombre ' . ($this->opciones->esGenerico() ? 'genérico' : 'comercial');
    }
}
