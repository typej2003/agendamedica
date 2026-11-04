<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`ecocardiografico_in`**: el informe del ecocardiograma de estrés de la consulta (CardioReport).
 *
 * Una fila por consulta, con la clave del legado `numhistoria` + `numconsulta` (el nombre de la tabla está así en el
 * esquema: es el «informe ecocardiográfico»). El mapa de columnas está en
 * `App\Clinica\Consulta\Cardiologia\MapaDelCardiologo`.
 */
class EcocardiograficoInforme extends Model
{
    protected $table = 'ecocardiografico_in';

    protected $guarded = ['id'];
}
