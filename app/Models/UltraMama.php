<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El eco mamario** (`ultra_mama`, WEB-6.4): la ecografía de mamas y axilas.
 *
 * ⚠️ **El DataWindow no se llama como la tabla**: el vivo es **`d_ultasonido_mamas`** (está en
 * `fuentes/ventana_consulta/`, no en `datawin_procesos/`) y escribe en `ultra_mama` (trampas 102/123/124). Lo
 * abre el botón *Mamario* de la pestaña *Estudio Ecográfico* (`open(w_cargar_visualizar_eco_mamario)`,
 * `w_consulta_principal.srw:20814`).
 *
 * ⚠️ **La clave es `historia` + `consulta`**: **una fila por consulta**. Es el más simple de los estudios: **no
 * tiene ningún desplegable con códigos** —el único es `referido`, que guarda la **cédula del médico
 * referente**— y sus cuatro campos cortos (`tranductor`, `menarquia`, `quirurgicos`, `hormonas`) son **texto
 * libre**, aunque en el dump traigan números.
 *
 * ⚠️ `tranductor` es el nombre real de la columna (typo del legado por *transductor*): **no se renombra**.
 * `para` está oculto en el DataWindow, nunca se imprime y en la muestra del dump está siempre vacío.
 *
 * Sus fotos van a `imagen_consulta` con `observacion = 'm'` (`TagDeImagen::MAMAS`), y sus plantillas de texto
 * son `formato_print` con `tipo = 'UM'`.
 */
class UltraMama extends Model
{
    use HasFactory;

    protected $table = 'ultra_mama';

    /** La tabla entera, como en los demás modelos del legado: qué **edita** la web lo dice su Action. */
    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'fecha', 'referido',
        'menarquia', 'para', 'quirurgicos', 'hormonas', 'tranductor',
        'mama_derecha', 'axila_derecha', 'mama_izquierda', 'axila_izquierda', 'conclusion', 'puro_texto',
    ];

    protected $casts = [
        'historia' => 'integer',
        'consulta' => 'integer',
        'referido' => 'integer',
        'fecha' => 'date',
    ];
}
