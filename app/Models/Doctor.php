<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catálogo de médicos a los que se refiere un paciente, por médico — tabla legada. No confundir con `medicos` (los usuarios del sistema). */
class Doctor extends Model
{
    protected $table = 'doctores';

    protected $fillable = ['reg_medico', 'cedula', 'apellidos', 'nombres', 'clinica', 'direccion', 'telefono', 'ciudad', 'nota', 'codeespecial'];

    // `decimal(15,0)`: sin el cast MySQL lo devuelve como texto y SQLite como número. Mismo cast que
    // `referencia.ceduladoctor`, para que el cliente los compare sin normalizar.
    protected $casts = ['cedula' => 'integer'];
}
