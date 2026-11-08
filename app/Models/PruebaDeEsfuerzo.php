<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`prueba_esfuerzo`**: la prueba de esfuerzo de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `numhistoria` + `numconsulta`. El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class PruebaDeEsfuerzo extends Model
{
    protected $table = 'prueba_esfuerzo';

    protected $guarded = ['id'];
}
