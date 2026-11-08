<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`texto_cardiaco_medico`**: los textos por defecto con los que el cardiólogo arranca sus informes (C12).
 *
 * Una fila por médico. Son los tres textos que el escritorio guarda en la fila única de `texto_cardiaco`.
 */
class TextoCardiacoMedico extends Model
{
    protected $table = 'texto_cardiaco_medico';

    protected $guarded = ['id'];
}
