<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El examen físico de una consulta** (`examen_fisico_nuevo`, WEB-4.3).
 *
 * Es la tabla del legado donde el escritorio guarda el **examen por aparatos** y el **examen
 * ginecológico**: la clave es `(historia, consulta)` —los números de la consulta, no un id— y la fila
 * la crea el escritorio al abrir la consulta (`w_consulta_principal.srw:5020`, un `INSERT ... VALUES`
 * con todo en NULL salvo los dos números).
 *
 * ⚠️ **No es `examen_fisico`**, que es otra tabla (la vieja, con `cardiopulmonal`/`riesgo_operatorio`):
 * el API la tiene porque el legado la tiene, pero el escritorio **no la lee ni la escribe** —0 filas en
 * la base de desarrollo y ninguna aparición en `fuentes/`—, así que la web tampoco.
 *
 * Los campos de antropometría **no** viven acá: son columnas de `consultas` (`peso`, `talla`, `fc`…).
 * El mapa completo de qué campo vive en qué tabla está en `App\Clinica\Consulta\ExamenFisicoDeLaConsulta`.
 */
class ExamenFisico extends Model
{
    use HasFactory;

    protected $table = 'examen_fisico_nuevo';

    /**
     * Las columnas de la tabla. **No es la lista de lo que la web edita**: eso lo dice
     * `App\Clinica\Consulta\EvaluacionFisica::columnas()`. Acá está todo lo que la tabla tiene —incluidas
     * `colposcopia` (F6) y los resúmenes `total_f`/`total_g`/`total_general` que escribe el escritorio—,
     * porque el modelo describe la tabla del legado y cualquier capa (un test, el sync, una tarea futura)
     * tiene que poder escribirla. La web simplemente no manda esas columnas.
     */
    protected $fillable = [
        'reg_medico',
        'historia',
        'consulta',
        'general',
        'cardiopulmonar',
        'tiroides',
        'mamas',
        'mama_derecha',
        'abdomen',
        'extremidades',
        'neurologico',
        'genitales_externos',
        'especulos',
        'tacto_vaginal',
        'tacto_rectal',
        'tension',
        'colposcopia',
        'total_f',
        'total_g',
        'total_general',
    ];

    protected $casts = [
        'historia' => 'integer',
        'consulta' => 'integer',
    ];
}
