<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`examen_fisico`**: el examen físico por sistemas del internista (la misma tabla que usa pediatría con otro mapa). Una fila por consulta, con la clave del legado `nrohistoria + nroconsulta`.
 *
 * Tabla de medicina interna (MedicReport, 2026-10-28). El mapa de columnas está en
 * `App\Clinica\Consulta\Interna\MapaDelInternista`.
 */
class ExamenFisicoInterno extends Model
{
    protected $table = 'examen_fisico';

    protected $guarded = ['id'];
}
