<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **El examen físico de pediatría** (WEB-12.2): la tabla `examen_fisico` del escritorio de pediatría (clave `nrohistoria` + `nroconsulta`), con sus nueve
 * columnas de texto (`cabeza` … `otros` y `fisico_resumen`). Es la que escribe el `UPDATE examen_fisico` de `w_consulta_principal.srw:2888`: en pediatría
 * **no existe** `examen_fisico_nuevo` (la de ginecología, `ExamenFisico`). Las columnas viejas de ginecología (`cardiopulmonal`…) no se tocan.
 */
class ExamenFisicoPediatrico extends Model
{
    protected $table = 'examen_fisico';

    protected $fillable = [
        'reg_medico', 'nrohistoria', 'nroconsulta', 'cabeza', 'torax', 'abdomen', 'pelvis', 'genitales', 'brazos', 'piernas', 'otros', 'fisico_resumen',
    ];
}
