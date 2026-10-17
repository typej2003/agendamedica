<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un examen de laboratorio pedido **a la pareja** del paciente — tabla legada `examen_pareja` (WEB-11.21). Se llavea por `nrohistoria` +
 * `nroconsulta` + `codeexamen`; el escritorio copia en cada fila el `nombre` y la `cedula` de la pareja (`w_consulta_examen_pareja.srw`).
 * `procedencia` y `orden` existen en el esquema y nadie las escribe.
 */
class ExamenPareja extends Model
{
    protected $table = 'examen_pareja';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'codeexamen', 'orden', 'cedula', 'nombre', 'procedencia'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer'];
}
