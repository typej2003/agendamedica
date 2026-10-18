<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una dosis de una vacuna (`vacunas_dosis`); `reg_medico` NULL = catalogo inicial (WEB-12.8). */
class VacunaDosis extends Model
{
    protected $table = 'vacunas_dosis';

    protected $fillable = ['reg_medico', 'codi_vacuna', 'dosis', 'dosis_descri', 'meses', 'obligatoria', 'sexo', 'frecuencia'];
}
