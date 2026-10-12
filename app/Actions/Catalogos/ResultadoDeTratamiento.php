<?php

namespace App\Actions\Catalogos;

use App\Models\RecipeGrupo;

/** Qué pasó al guardar un tratamiento (WEB-8.1a): para decirle al médico lo que cambió, con nombres. */
final class ResultadoDeTratamiento
{
    /**
     * @param  array<int,string>  $agregados  los medicamentos que entraron
     * @param  array<int,string>  $modificados  los que cambiaron de cantidad o de indicaciones
     * @param  array<int,string>  $quitados  los que salieron
     * @param  array<int,string>  $bloqueados  los que tienen una edición más nueva (de otro equipo) y no se pisaron
     */
    public function __construct(
        public readonly RecipeGrupo $tratamiento,
        public readonly bool $esNuevo,
        public readonly bool $nombreCambiado,
        public readonly array $agregados,
        public readonly array $modificados,
        public readonly array $quitados,
        public readonly bool $reordenado,
        public readonly array $bloqueados,
    ) {
    }

    public function sinCambios(): bool
    {
        return ! $this->esNuevo && ! $this->nombreCambiado && ! $this->reordenado
            && $this->agregados === [] && $this->modificados === [] && $this->quitados === [] && $this->bloqueados === [];
    }

    public function huboConflicto(): bool
    {
        return $this->bloqueados !== [];
    }
}
