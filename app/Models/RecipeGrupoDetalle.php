<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un medicamento de un tratamiento, con su cantidad e indicación por defecto. Tabla legada. */
class RecipeGrupoDetalle extends Model
{
    protected $table = 'recipe_grupo_detalle';

    protected $fillable = ['reg_medico', 'codigo', 'codemedicina', 'descripcion', 'indicaciones', 'cantidad', 'orden'];
}
