<?php

namespace App\Actions\Consulta;

use App\Models\RecipePareja;
use Illuminate\Support\Collection;

/**
 * Qué pasó al guardar el récipe de la pareja (`GuardarRecipeDeLaPareja`, WEB-5.1b): lo que se creó, lo que se
 * cambió, lo que se quitó y lo que el sync no dejó pisar, para que la pantalla lo cuente en lugar de decir
 * «Guardado». Los nombres son los de la fila (el texto que el médico ve), no ids.
 */
final class ResultadoDeRecipeDeLaPareja
{
    /**
     * @param  bool  $esNuevo  `true` si la consulta no tenía récipe de la pareja y se creó ahora
     * @param  Collection<int,RecipePareja>  $filas  las filas de `recipes_pareja` que se **crearon**
     * @param  array<int,string>  $agregados  los medicamentos que entraron
     * @param  array<int,string>  $modificados  los medicamentos cuya cantidad o indicaciones cambiaron
     * @param  array<int,string>  $quitados  los medicamentos que salieron
     * @param  bool  $parejaCambiada  si se reescribió la cédula o el nombre de la pareja en las filas que ya estaban
     * @param  array<int,string>  $bloqueados  lo que no se escribió porque el sync tiene una edición **más nueva**
     */
    public function __construct(
        public readonly bool $esNuevo,
        public readonly Collection $filas,
        public readonly array $agregados = [],
        public readonly array $modificados = [],
        public readonly array $quitados = [],
        public readonly bool $parejaCambiada = false,
        public readonly array $bloqueados = [],
    ) {
    }

    /** ¿Hubo algún conflicto con una edición más nueva? */
    public function huboConflicto(): bool
    {
        return $this->bloqueados !== [];
    }

    /** ¿No se escribió nada y no hubo conflicto? (guardar el récipe tal como estaba) */
    public function sinCambios(): bool
    {
        return ! $this->esNuevo
            && $this->agregados === []
            && $this->modificados === []
            && $this->quitados === []
            && ! $this->parejaCambiada
            && $this->bloqueados === [];
    }
}
