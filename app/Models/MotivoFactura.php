<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`motivo_factura`**: el catálogo de conceptos facturables del médico (consulta, procedimiento, material…), con su
 * `monto` y el `monto_seg` para el paciente con seguro. Es de donde salen los ítems de la pre-factura (F9).
 */
class MotivoFactura extends Model
{
    protected $table = 'motivo_factura';

    protected $fillable = ['reg_medico', 'codigo', 'descripcion', 'monto', 'monto_seg', 'tipo'];

    protected $casts = ['monto' => 'float', 'monto_seg' => 'float'];
}
