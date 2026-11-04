<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`dd_arterial_mi`**: el Doppler arterial de los **miembros inferiores** de la consulta (CardioReport).
 *
 * ⚠️ La tabla ya existía en el API (la usa ginecología) y su clave del legado es `numhistoria` + **`num_consulta`**
 * (con guion bajo), no `numconsulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class DopplerArterialMiembrosInferiores extends Model
{
    protected $table = 'dd_arterial_mi';

    protected $guarded = ['id'];
}
