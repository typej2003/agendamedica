<?php

namespace App\Actions\Recipes;

use App\Models\RecipeVerificacion;

/** **Reactiva el QR de un récipe anulado** (WEB-5.6): el mismo token vuelve a verificar. Idempotente. */
final class ReactivarVerificacionDeRecipe
{
    public function ejecutar(RecipeVerificacion $verificacion): RecipeVerificacion
    {
        if ($verificacion->estaAnulada()) {
            $verificacion->forceFill(['revocado_en' => null])->save();
        }

        return $verificacion;
    }
}
