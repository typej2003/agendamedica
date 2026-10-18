<?php

namespace App\Models;

/**
 * **El eco obstétrico del segundo trimestre** (`eco_obstetrico_tercer_2`, WEB-11.14): Alexander pidió ver el 2.º y el 3.er trimestre
 * **separados** (2026-10-15). En el escritorio los dos compartían `eco_obstetrico_tercer` y cargar uno pisaba al otro; el 2.º pasa a la
 * tabla hermana `eco_obstetrico_tercer_2` (mismas 69 columnas, vacía en el legado), y `eco_obstetrico_tercer` queda como el 3.º.
 */
class EcoObstetricoSegundo extends EcoObstetricoTercer
{
    protected $table = 'eco_obstetrico_tercer_2';
}
