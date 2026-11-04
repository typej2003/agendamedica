<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`ddflujo_venoso`**: el Doppler venoso de los **miembros inferiores** de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `numhistoria` + `numconsulta`. La grilla de venas y fases está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class DopplerVenoso extends Model
{
    protected $table = 'ddflujo_venoso';

    protected $guarded = ['id'];
}
