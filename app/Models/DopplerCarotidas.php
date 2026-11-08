<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`dd_arterias_carotidas_dos`**: el Doppler de arterias **carótidas y vertebrales** de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `numhistoria` + `numconsulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class DopplerCarotidas extends Model
{
    protected $table = 'dd_arterias_carotidas_dos';

    protected $guarded = ['id'];
}
