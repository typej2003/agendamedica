<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **Una gesta de la historia** (`pre_natal_observaciones`, WEB-6.6): el embarazo que el escritorio guarda
 * en la pestaña *Prenatal* del control de embarazo.
 *
 * ⚠️ **La clave es `historia` + `gesta_clave`**, las dos con `key=yes` en el DataWindow de la pestaña
 * (`d_pre_natal_observa.srd`): una historia puede tener **varias gestas** y el botón *Nueva Gesta* agrega
 * una. **`gesta_clave` es nullable** en el esquema y en los datos viejos viene vacía: una fila sin número es
 * la gesta **1** (la lectura y la numeración usan `COALESCE(gesta_clave, 1)`).
 *
 * **No se llavea por la consulta**: el control prenatal es del embarazo, no de la consulta.
 *
 * Las columnas `partos`, `cesarea`, `abortos`, `otros`, `final`, `multiple` y `cantidad` están en la tabla
 * —las escribe el escritorio desde otras pantallas— pero **este DataWindow no las edita**: la web las deja
 * como estén (`GuardarGestaDelEmbarazo::COLUMNAS_QUE_NO_SE_TOCAN`).
 */
class PreNatalObservacion extends Model
{
    use HasFactory;

    protected $table = 'pre_natal_observaciones';

    /**
     * Las columnas de la tabla. **No es la lista de lo que la web edita**: eso lo dice
     * `App\Actions\Embarazo\GuardarGestaDelEmbarazo::COLUMNAS`. Acá está todo lo que la tabla tiene, porque
     * el modelo describe la tabla del legado y cualquier capa (el sync, un test) tiene que poder escribirla.
     */
    protected $fillable = [
        'reg_medico',
        'historia',
        'gesta_clave',
        'fur',
        'menarquia',
        'gestas',
        'parto',
        'tipiaje',
        'tipiaje_cony',
        'observaciones',
        // Columnas que la pestaña *Prenatal* no toca (ver el docblock de la clase).
        'partos',
        'cesarea',
        'abortos',
        'otros',
        'final',
        'multiple',
        'cantidad',
    ];

    /** `fur` queda como el `AAAA-MM-DD` del `<input type="date">`: la columna guarda exactamente eso. */
    protected $casts = [
        'historia' => 'integer',
        'gesta_clave' => 'integer',
        'gestas' => 'integer',
    ];
}
