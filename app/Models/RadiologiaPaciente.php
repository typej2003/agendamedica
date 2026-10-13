<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un estudio de imágenes pedido en una consulta — tabla legada `radiologia_paciente` (WEB-5.4). Se llavea por `nrohistoria` +
 * `nroconsulta` + `coderadio`. Ojo: `coderadio` es el `codeexamen` de un examen del catálogo `examenes` con `codetipo = '002'`
 * (estudio de imágenes), **no** un código de la tabla `radiologias` (ninguna fila la nombra). `nroopcion` siempre es 1.
 */
class RadiologiaPaciente extends Model
{
    protected $table = 'radiologia_paciente';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'coderadio', 'nroopcion', 'orden'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer', 'nroopcion' => 'integer', 'orden' => 'integer'];
}
