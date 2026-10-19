<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`riesgo_paciente`**: los factores de riesgo quirúrgico **marcados al paciente** (clave del legado: `historia`,
 * `descripcion`, `sub_tipo`), con los puntos de cada uno. El escritorio los da de alta y de baja desde la pestaña de
 * riesgo del ítem «I.Q.» (`w_consulta_principal.srw:14288` y `:14323`).
 *
 * ⚠️ **No se llavea por consulta**: el riesgo es del paciente (así lo hace el `INSERT` del escritorio).
 */
class RiesgoPaciente extends Model
{
    protected $table = 'riesgo_paciente';

    protected $fillable = ['reg_medico', 'historia', 'descripcion', 'sub_tipo', 'punto'];

    protected $casts = ['historia' => 'integer', 'punto' => 'integer'];
}
