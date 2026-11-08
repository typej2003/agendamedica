<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`ecocardiograma_ete`**: el ecocardiograma transesofágico (ETE) de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `numhistoria` + `numconsulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class EcocardiogramaEte extends Model
{
    protected $table = 'ecocardiograma_ete';

    protected $guarded = ['id'];
}
