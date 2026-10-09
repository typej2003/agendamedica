<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivote `especialidad_modulo`: qué módulos tiene cada especialidad, si ya está **implementado** y
 * qué roles lo ven. Existe como modelo (y no como pivote anónimo) para castear `visible_para`.
 */
class EspecialidadModulo extends Pivot
{
    protected $table = 'especialidad_modulo';

    public $incrementing = true;

    protected $casts = [
        'implementado' => 'boolean',
        'visible_para' => 'array',
        'orden' => 'integer',
    ];
}
