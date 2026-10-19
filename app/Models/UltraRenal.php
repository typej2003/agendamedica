<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`ultra_renal`**: el eco renal de una consulta (nefrología pediátrica), una fila por consulta con la clave
 * `historia` + `consulta`.
 *
 * Las medidas van por riñón, con el prefijo en la columna: `rd_*` el derecho y `ri_*` el izquierdo. El mapa de la
 * pantalla está en `App\Clinica\Consulta\EcoRenal`.
 */
class UltraRenal extends Model
{
    protected $table = 'ultra_renal';

    protected $guarded = ['id'];

    protected $casts = ['fecha' => 'date', 'historia' => 'integer', 'consulta' => 'integer'];
}
