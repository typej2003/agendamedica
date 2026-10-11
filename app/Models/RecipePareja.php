<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un medicamento del **récipe de la pareja** (WEB-5.1b) — tabla legada real `recipes_pareja`.
 *
 * Se llavea por `nrohistoria` + `nroconsulta` (+ `codemedicina`), igual que `recipes`, pero **no tiene
 * columna `recipe` ni cabecera** (`recipe_detalle`): la pareja es **una por consulta** y sus datos
 * (`cedula`, `nombre`) se repiten en **todas** sus filas. Ver `App\Clinica\Consulta\RecipeDeLaPareja`.
 */
class RecipePareja extends Model
{
    protected $table = 'recipes_pareja';

    protected $fillable = [
        'reg_medico',
        'nrohistoria',
        'nroconsulta',
        'codemedicina',
        'indicaciones',
        'cantidad',
        'descripcion',
        'orden',
        'cedula',
        'nombre',
        'procedencia',
    ];

    protected $casts = [
        'nrohistoria' => 'integer',
        'nroconsulta' => 'integer',
        'cantidad' => 'integer',
        'orden' => 'integer',
    ];
}
