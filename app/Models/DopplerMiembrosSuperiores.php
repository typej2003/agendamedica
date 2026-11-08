<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`dd_arterial_venoso_ms`**: el Doppler arterial y venoso de los **miembros superiores** de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `historia` + `consulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class DopplerMiembrosSuperiores extends Model
{
    protected $table = 'dd_arterial_venoso_ms';

    protected $guarded = ['id'];
}
