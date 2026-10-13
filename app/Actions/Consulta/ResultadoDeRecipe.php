<?php

namespace App\Actions\Consulta;

use App\Models\Recipe;
use App\Models\RecipeDetalle;
use Illuminate\Support\Collection;

/**
 * Qué pasó al guardar un récipe (`GuardarRecipe`, WEB-5.1): lo que se creó, lo que se cambió, lo que se
 * quitó y lo que el sync no dejó pisar, para que la pantalla lo cuente en lugar de decir "Guardado".
 *
 * Los nombres que se guardan son los de la fila (el texto que el médico ve), no ids.
 */
final class ResultadoDeRecipe
{
    /**
     * @param  int  $numero  el número del récipe dentro de la consulta
     * @param  bool  $esNuevo  `true` si el récipe se creó ahora (con el número que le tocó)
     * @param  RecipeDetalle|null  $cabecera  la cabecera `recipe_detalle` (la del récipe nuevo, o la que
     *                                        ya tenía); `null` si el récipe viejo no tiene
     * @param  Collection<int,Recipe>  $filas  las filas de `recipes` que el récipe **creó**, en el orden de
     *                                         los medicamentos que llegaron (el móvil devuelve sus ids)
     * @param  array<int,string>  $agregados  los medicamentos que entraron
     * @param  array<int,string>  $modificados  los medicamentos cuya cantidad o indicaciones cambiaron
     * @param  array<int,string>  $quitados  los medicamentos que salieron
     * @param  bool  $fechaCambiada  si se escribió una fecha de emisión distinta
     * @param  array<int,string>  $bloqueados  lo que no se escribió porque el sync tiene una edición **más nueva**
     */
    public function __construct(
        public readonly int $numero,
        public readonly bool $esNuevo,
        public readonly ?RecipeDetalle $cabecera,
        public readonly Collection $filas,
        public readonly array $agregados = [],
        public readonly array $modificados = [],
        public readonly array $quitados = [],
        public readonly bool $fechaCambiada = false,
        public readonly array $bloqueados = [],
    ) {
    }

    /** ¿Hubo algún conflicto con una edición más nueva? */
    public function huboConflicto(): bool
    {
        return $this->bloqueados !== [];
    }

    /** ¿No se escribió nada y no hubo conflicto? (guardar un récipe tal como estaba) */
    public function sinCambios(): bool
    {
        return ! $this->esNuevo
            && $this->agregados === []
            && $this->modificados === []
            && $this->quitados === []
            && ! $this->fechaCambiada
            && $this->bloqueados === [];
    }
}
