<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`oscilometria`**: la oscilometría (índices y gradientes segmentarios) de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `historia` + `consulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class Oscilometria extends Model
{
    protected $table = 'oscilometria';

    protected $guarded = ['id'];
}
