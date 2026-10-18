<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **El catálogo de antecedentes del médico** (`antecedentes`, tabla legada).
 *
 * `codantecedente` (cuatro dígitos) → `descripcion` y `codtipo` (el tipo, que sale de
 * `tipo_antecedente`). El mapa del dato —los "vacíos" del legado, los repetidos del catálogo y la
 * casilla de Alerta— está en `App\Clinica\Consulta\AntecedentesDelPaciente`; este modelo existe porque
 * el volcado del móvil (A4b de PLAN-MOVIL.md) necesita un modelo para bajar el catálogo por delta, como
 * `Diagnostico` o `Vademecum`.
 */
class Antecedente extends Model
{
    protected $table = 'antecedentes';

    protected $fillable = ['reg_medico', 'codantecedente', 'descripcion', 'codtipo'];
}
