<?php

namespace App\Actions\Recipes;

use App\Models\RecipeVerificacion;

/**
 * **Anula el QR de un récipe** (WEB-5.6): desde ese momento la página pública dice «Anulado por el médico». Es
 * idempotente (anular lo anulado no cambia la fecha) y reversible con `ReactivarVerificacionDeRecipe`.
 */
final class AnularVerificacionDeRecipe
{
    public function ejecutar(RecipeVerificacion $verificacion): RecipeVerificacion
    {
        if (! $verificacion->estaAnulada()) {
            $verificacion->forceFill(['revocado_en' => now()])->save();
        }

        return $verificacion;
    }
}
