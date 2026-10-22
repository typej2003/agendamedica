<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **El candado suave de una consulta** (`consulta_sesiones`): el dispositivo que la tiene abierta y su última
 * actividad. Una fila por consulta. Ver `App\Actions\Consulta\RegistrarSesionDeConsulta`.
 */
class ConsultaSesion extends Model
{
    protected $table = 'consulta_sesiones';

    protected $fillable = [
        'consulta_id', 'reg_medico', 'user_id', 'dispositivo_id', 'abierta_en', 'ultima_actividad_en',
    ];

    protected $casts = [
        'abierta_en' => 'datetime',
        'ultima_actividad_en' => 'datetime',
    ];
}
