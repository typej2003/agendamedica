<?php

namespace App\Http\Resources;

use App\Models\RecipeFormato;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Formato de impresión del récipe (ROADMAP.md Paso 18.A). Sale siempre completo: un médico sin fila, o
 * con una fila vieja a la que le faltan opciones, recibe los defaults del legado — el cliente no
 * necesita saber cuáles son.
 *
 * @mixin RecipeFormato
 */
class RecipeFormatoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'elementos' => $this->elementosResueltos(),
            'color_linea' => $this->color_linea ?? 'negro',
            'firma_url' => $this->firma ? Storage::disk('public')->url($this->firma) : null,
            'sello_url' => $this->sello ? Storage::disk('public')->url($this->sello) : null,
            'sello_posicion' => $this->sello_posicion ?? 'centro',
            // Marca de versión para que el cliente sepa si tiene que volver a bajar las imágenes
            // (firma/sello) a su copia offline, sin comparar archivos.
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
