<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`his_con_pre_factura`**: los ítems de la **pre-factura** de una consulta (F9). Una fila por concepto cobrado,
 * con la clave de negocio `numhistoria` + `nroconsulta` + `codigo` (el `codigo` es el del catálogo `motivo_factura`).
 *
 * `id_registro` es el correlativo del escritorio (`double`, `NOT NULL`): la web lo asigna como el mayor del médico + 1.
 * `descripcion` (`varchar(45)`) se copia del catálogo al cargar el ítem, como el resto de las filas de la consulta que
 * llevan un nombre de catálogo (`diagnostico_paciente.detalle_diagnostco`).
 */
class PreFacturaConsulta extends Model
{
    protected $table = 'his_con_pre_factura';

    protected $fillable = [
        'reg_medico', 'id_registro', 'numhistoria', 'nroconsulta', 'codigo', 'descripcion', 'cantidad', 'monto',
    ];

    protected $casts = [
        'id_registro' => 'float',
        'numhistoria' => 'integer',
        'nroconsulta' => 'integer',
        'cantidad'    => 'float',
        'monto'       => 'float',
    ];
}
