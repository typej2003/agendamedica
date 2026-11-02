<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un examen pedido en una consulta — tabla legada `examen_paciente` (WEB-5.4). Se llavea por `nrohistoria` + `nroconsulta` +
 * `codeexamen`.
 *
 * **`resultado` y `vinculante` son del escritorio y la web también las escribe desde O-47 (2026-11-02)**: `resultado` es el
 * texto corto del resultado (`varchar(30)`) y `vinculante = 'S'` marca el examen que sale en el informe pre-operatorio (las
 * cinco hojas del escritorio filtran por esa letra; `NULL` y `'N'` son «no sale»). Las escribía la pantalla
 * `ventana_consulta/d_resultados_examenes.srd`, con `update="examen_paciente"`; estaban vacías en toda la base real.
 */
class ExamenPaciente extends Model
{
    protected $table = 'examen_paciente';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'codeexamen', 'resultado', 'vinculante'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer'];
}
