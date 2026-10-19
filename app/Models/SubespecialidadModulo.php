<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivote `subespecialidad_modulo`: qué módulos del menú enciende cada postgrado, si ya está
 * **implementado** y qué roles lo ven. Existe como modelo para castear `visible_para`, igual que
 * `EspecialidadModulo`.
 */
class SubespecialidadModulo extends Pivot
{
    protected $table = 'subespecialidad_modulo';

    public $incrementing = true;

    protected $casts = [
        'implementado' => 'boolean',
        'visible_para' => 'array',
        'orden' => 'integer',
    ];
}
