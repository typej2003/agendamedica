<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** **`riesgo`**: el catálogo de factores de riesgo quirúrgico del médico (`clave`, `puntos`, `descripcion`, `sub_tipo`). */
class Riesgo extends Model
{
    protected $table = 'riesgo';

    protected $fillable = ['reg_medico', 'clave', 'puntos', 'descripcion', 'sub_tipo'];

    protected $casts = ['clave' => 'integer', 'puntos' => 'integer'];
}
