<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`ecocardiografia`**: el ecocardiograma transtorácico de la consulta (CardioReport, instalación `miconsultaca`).
 *
 * Una fila por consulta, con la clave del legado `numhistoria` + `numconsulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class Ecocardiografia extends Model
{
    protected $table = 'ecocardiografia';

    protected $guarded = ['id'];
}
