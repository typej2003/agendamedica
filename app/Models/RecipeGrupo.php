<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tratamiento (plantilla de récipe) del médico. Tabla legada; sus medicamentos están en `recipe_grupo_detalle` por `codigo`. */
class RecipeGrupo extends Model
{
    protected $table = 'recipe_grupo';

    protected $fillable = ['reg_medico', 'codigo', 'tratamiento'];
}
