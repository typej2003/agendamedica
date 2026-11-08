<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`implantacion_marcapasos`**: el implante y la programación del marcapasos de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `historia` + `consulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class ImplantacionMarcapasos extends Model
{
    protected $table = 'implantacion_marcapasos';

    protected $guarded = ['id'];
}
