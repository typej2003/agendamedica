<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`orden_ingreso`**: la orden de ingreso de una consulta de traumatología (clave `historia` + `consulta`). Lleva el
 * diagnóstico (`idx`), las recomendaciones (`texto`), las catorce casillas `reco_*` y las observaciones (`detalle`).
 */
class OrdenDeIngreso extends Model
{
    protected $table = 'orden_ingreso';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_operacion' => 'date',
        'fecha_reporte' => 'date',
    ];
}
