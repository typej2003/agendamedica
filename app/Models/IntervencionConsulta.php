<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`interven_consulta`**: las intervenciones (procedimientos quirúrgicos) cargadas en una consulta de cirugía
 * pediátrica. Clave del legado: `historia` + `consulta` + `codigo_inter` (tres caracteres), **una fila por
 * intervención**.
 *
 * `descripcion` es `char(100)` y `detalle` es texto largo, como en el escritorio (`tabpage_21` del ítem «I.Q.»).
 */
class IntervencionConsulta extends Model
{
    protected $table = 'interven_consulta';

    protected $fillable = ['reg_medico', 'historia', 'consulta', 'codigo_inter', 'descripcion', 'detalle'];

    protected $casts = ['historia' => 'integer', 'consulta' => 'integer'];
}
