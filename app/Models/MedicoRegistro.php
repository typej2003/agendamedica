<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Acceso a los datos de un `reg_medico`.
 *
 * Una fila es **de un médico** (`medico_id`: su propio registro o uno que le asignaron desde el panel)
 * o **de una cuenta que no es médico** (`user_id`: la secretaría de un consultorio). Los dos campos son
 * nullable a propósito; el flujo que crea filas llena uno u otro.
 */
class MedicoRegistro extends Model
{
    use HasFactory;

    protected $fillable = [
        'medico_id',
        'user_id',
        'reg_medico',
    ];
}
