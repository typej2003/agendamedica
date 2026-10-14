<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El eco abdominal** (`ultra_abdominal`, WEB-6.4): la ecografía abdominal general.
 *
 * ⚠️ **El DataWindow no se llama como la tabla**: el vivo es **`d_ultasonido_abdominal`** (en
 * `fuentes/ventana_consulta/`), que escribe en `ultra_abdominal` (trampas 102/123/124). En la consulta de
 * ginecología es la pestaña *Eco Abdominal* (`dw_67`, `w_consulta_principal.srw:23110`) y **el mismo estudio,
 * con la misma tabla y el mismo DataWindow, lo usan cirugía y pediatría**: no es un estudio gineco-obstétrico,
 * pero el ginecólogo lo cargó (57 filas del dump, todas de `gineco-00001`).
 *
 * ⚠️ **La clave es `historia` + `consulta`**: **una fila por consulta**.
 *
 * ⚠️ **`prostata` y `vejiga_urina` son de urología y en ginecología están OCULTAS** en el formulario y en la
 * hoja del escritorio (`visible="0"`), y en el dump siguen vacías. Decisión del usuario (2026-10-14): la web
 * **no las ofrece ni las imprime**, y las columnas quedan intactas para no pisar lo que otro build haya
 * escrito. `vol_res_v_p_m` (residuo post-micción) **sí** se muestra y se imprime en ginecología.
 *
 * Sus fotos van a `imagen_consulta` con `observacion = 'a'` (`TagDeImagen::ABDOMINAL`), y sus plantillas de
 * texto son `formato_print` con `tipo = 'UA'`.
 */
class UltraAbdominal extends Model
{
    use HasFactory;

    protected $table = 'ultra_abdominal';

    /** La tabla entera, como en los demás modelos del legado: qué **edita** la web lo dice su Action. */
    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'fecha', 'transductor', 'referido',
        'higado', 'vesicula_bilial', 'porta_coledoco', 'pancreas', 'rinon_derecho', 'rinon_izquierdo',
        // De urología: en ginecología no se ofrecen (ver el docblock), pero se conservan.
        'prostata', 'vejiga_urina',
        'vol_res_v_p_m', 'ascitis', 'retroperitoneo', 'vasos_sanguineos',
        'otros', 'conclusiones', 'puro_texto',
    ];

    protected $casts = [
        'historia' => 'integer',
        'consulta' => 'integer',
        'referido' => 'integer',
        'fecha' => 'date',
    ];
}
