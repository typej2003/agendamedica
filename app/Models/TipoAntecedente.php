<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **El catálogo de tipos de antecedente** (`tipo_antecedente`, tabla legada): `codetipo` →
 * `descripcion`, más `tipoantecedente`.
 *
 * Sus siete filas son las solapas del escritorio (Per., Fam., H.P., Obst., Gine., A.S., Otros), en el
 * orden de `codetipo` — no el alfabético—. Lo baja el volcado del móvil (A4b de PLAN-MOVIL.md) para que
 * el teléfono pueda agrupar los antecedentes del paciente igual que la web; el mapa del dato está en
 * `App\Clinica\Consulta\AntecedentesDelPaciente`.
 */
class TipoAntecedente extends Model
{
    protected $table = 'tipo_antecedente';

    protected $fillable = ['reg_medico', 'codetipo', 'descripcion', 'tipoantecedente'];
}
