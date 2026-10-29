<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`pre_infec`**: el riesgo de infección de la herida operatoria de la evaluación pre-operatoria. Una fila por consulta, con la clave del legado `historia + consulta`.
 *
 * Tabla de medicina interna (MedicReport, 2026-10-28). El mapa de columnas está en
 * `App\Clinica\Consulta\Interna\MapaDelInternista`.
 */
class PreInfeccion extends Model
{
    protected $table = 'pre_infec';

    protected $guarded = ['id'];
}
