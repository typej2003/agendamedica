<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **El catálogo de parentescos** del representante del paciente (WEB-3.4).
 *
 * Es una tabla **de AppDDR**, no del legado: `representante.codeparentesco` es `char(1)` y el
 * PowerBuilder no la lee ni la escribe en ninguna ventana ni DataWindow (barrido de
 * `Docs/MICONSULTAGI-legado/fuentes` y `fuentes-pediatria`, 2026-10-10). El código
 * de un carácter **no es un límite elegido acá**: lo impone la columna legada que tiene que llenar.
 *
 * La tabla no lleva `reg_medico` ni viaja por el sync del escritorio: es un catálogo único, como
 * `modulos_clinicos`. El modelo está para el seeder y para que la relación se lea por nombre; la
 * consulta de la pantalla es `App\Clinica\Pacientes\CatalogoDeParentescos`.
 */
class Parentesco extends Model
{
    protected $table = 'parentesco';

    protected $fillable = ['codigo', 'nombre', 'orden'];
}
