<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una vacuna del catalogo (`vacunas`, tabla del legado de pediatria). Las filas con `reg_medico` NULL son el **catalogo inicial** que arma el
 * admin (WEB-12.8); las del medico llevan su `reg_medico`.
 */
class Vacuna extends Model
{
    protected $table = 'vacunas';

    protected $fillable = [
        'reg_medico', 'codi_vacuna', 'vacuna', 'edad_inicial_meses', 'frecuencia', 'total', 'cobertura', 'dosis', 'fija',
        'edad_final_meses', 'sexo', 'cantidad', 'preciov', 'tipo_vacu', 'adulto_ni', 'prioridad', 'inventa_propia',
    ];
}
