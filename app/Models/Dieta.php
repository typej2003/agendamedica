<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una dieta del catálogo del médico (`dietas`, tabla del legado de pediatría): `cod_dieta` (dos caracteres), `dietas` (el título), `descripcion` (el texto que se
 * copia a la consulta) y `calorias`. Alimenta la pestaña *Alimentación* de la consulta pediátrica.
 */
class Dieta extends Model
{
    protected $table = 'dietas';

    protected $fillable = ['reg_medico', 'cod_dieta', 'dietas', 'descripcion', 'calorias'];
}
