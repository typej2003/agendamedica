<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cabecera de un récipe (emisión, vencimiento en días, nota). Tabla legada. */
class RecipeDetalle extends Model
{
    protected $table = 'recipe_detalle';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'recipe', 'fe_emision', 'fe_vence', 'nota'];
}
