<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de exámenes y estudios por médico — tabla legada `examenes` (`codeexamen` de tres dígitos en la práctica,
 * `examen` de hasta 45 y `codetipo`, que apunta a `tipos_examenes`). WEB-8.1b.
 */
class Examen extends Model
{
    protected $table = 'examenes';

    protected $fillable = ['reg_medico', 'codeexamen', 'examen', 'codetipo'];
}
