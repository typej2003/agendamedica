<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`datos_grafica`**: los valores del «Análisis evolutivo» de la consulta (laboratorio, cintura e IMC). Una fila por consulta, con la clave del legado `historia + consulta`.
 *
 * Tabla de medicina interna (MedicReport, 2026-10-28). El mapa de columnas está en
 * `App\Clinica\Consulta\Interna\MapaDelInternista`.
 */
class DatosGrafica extends Model
{
    protected $table = 'datos_grafica';

    protected $guarded = ['id'];
}
