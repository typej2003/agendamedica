<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`ekg`**: el electrocardiograma de la consulta. Una fila por consulta, con la clave del legado `numhistoria + numconsulta`.
 *
 * Tabla de medicina interna (MedicReport, 2026-10-28). El mapa de columnas está en
 * `App\Clinica\Consulta\Interna\MapaDelInternista`.
 */
class Ekg extends Model
{
    protected $table = 'ekg';

    protected $guarded = ['id'];
}
