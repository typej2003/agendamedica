<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`ortopedia`**: la plantilla ortopédica de una consulta de traumatología (clave `nrohistoria` + `nroconsulta`).
 */
class Ortopedia extends Model
{
    protected $table = 'ortopedia';

    protected $guarded = ['id'];
}
