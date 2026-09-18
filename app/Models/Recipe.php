<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Versión mínima del récipe médico — ver la nota en la migración
 * `create_recipes_table` sobre lo que se deja fuera a propósito.
 */
class Recipe extends Model
{
    use HasFactory;

    protected $table = 'recipes';

    protected $fillable = [
        'reg_medico',
        'numhistoria',
        'nroconsulta',
        'fecha',
        'codemedicina',
        'descripcion',
        'indicaciones',
        'cantidad',
        'medico',
    ];

    protected $casts = [
        'fecha' => 'date',
        'numhistoria' => 'integer',
        'nroconsulta' => 'integer',
        'cantidad' => 'integer',
    ];
}
