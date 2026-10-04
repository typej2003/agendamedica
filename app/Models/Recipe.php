<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Récipe médico — tabla legada real (`nrohistoria`, no `numhistoria`; ver la
 * migración `create_recipes_table`). Versión mínima: ver esa misma migración
 * para lo que se deja fuera a propósito.
 */
class Recipe extends Model
{
    use HasFactory;

    protected $table = 'recipes';

    protected $fillable = [
        'reg_medico',
        'nrohistoria',
        'nroconsulta',
        'codemedicina',
        'indicaciones',
        'cantidad',
        'orden',
        'descripcion',
        'fecha',
        'recipe',
        'comple',
    ];

    protected $casts = [
        'fecha' => 'date',
        'nrohistoria' => 'integer',
        'nroconsulta' => 'integer',
        'cantidad' => 'integer',
        'orden' => 'integer',
        'recipe' => 'integer',
    ];
}
