<?php

namespace App\Clinica\Impresion;

use App\Models\Evolucion;
use App\Models\RecipeFormato;

/**
 * **Lo que el médico tiene guardado** como formato de impresión (WEB-8.2): sus dos filas (`evolucion` y `recipe_formatos`,
 * que pueden no existir todavía) y el membrete ya resuelto —con los valores del legado donde falta algo y las imágenes
 * comprobadas en el disco— que es lo que imprime la hoja.
 *
 * Lo arma `FormatoDelMedico`. No lee nada por su cuenta.
 */
final class FormatoGuardado
{
    public function __construct(
        public readonly string $registro,
        public readonly ?Evolucion $evolucion,
        public readonly ?RecipeFormato $formato,
        public readonly Membrete $membrete,
    ) {
    }

    /** El texto crudo de una columna de `evolucion` (para los campos del formulario), o `null` si no hay. */
    public function texto(string $columna): ?string
    {
        $valor = $this->evolucion?->getAttribute($columna);

        return is_string($valor) && trim($valor) !== '' ? $valor : null;
    }

    /** ¿El médico ya guardó alguna vez su formato (hay fila en `recipe_formatos`)? */
    public function tieneFormato(): bool
    {
        return $this->formato !== null;
    }
}
