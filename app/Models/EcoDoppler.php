<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El eco Doppler perinatal** (`eco_doppler`, WEB-6.3): el estudio de las cuatro arterias del embarazo.
 *
 * Lo carga el médico desde la pestaña *Estudio Ecográfico* de la consulta (`open(w_cargar_visualizar_eco_doppler)`,
 * `w_consulta_principal.srw:21096`) y su DataWindow vivo es **`d_eco_doppler`**, que está en
 * `fuentes/ventana_consulta/d_eco_doppler.srd` — **no** en `datawin_procesos/` (trampas 123 y 124)—. La tabla
 * **sí** se llama como el DataWindow.
 *
 * ⚠️ **La clave es `historia` + `consulta`** —no `numhistoria`/`nroconsulta` como el eco pélvico—: **una fila
 * por consulta**. `gesta`, `fur` y `fecha` son datos que el escritorio teclea o completa solo, no la clave.
 *
 * ⚠️ `fecha` **no se puede cargar desde ninguna pantalla** (no está colocada en el DataWindow): la pone el
 * código al insertar la fila o queda `NULL`. Sus plantillas de texto son `formato_print` con `tipo = 'ED'`, y
 * sus fotos van a `imagen_consulta` con `observacion = 'ed'` (`TagDeImagen::DOPPLER`).
 *
 * Las columnas salen del DataWindow vivo y de la migración del esquema del legado (relevado el 2026-10-14).
 */
class EcoDoppler extends Model
{
    use HasFactory;

    protected $table = 'eco_doppler';

    /** La tabla entera, como en los demás modelos del legado: qué **edita** la web lo dice su Action. */
    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'fur', 'gesta', 'para', 'texto', 'puro_texto', 'fecha',
        // La grilla de cuatro vasos: por fila (uterina derecha, uterina izquierda, umbilical, cerebral media) y
        // por columna (`_menor` = índice de pulsatibilidad, `_mayor` = índice de resistencia, `relacion_*` =
        // relación S/D, `pico_*` = pico de velocidad sistólica). El escritorio guarda los milímetros como texto.
        'arteria_uraina_menor', 'arteria_uraina_menor_izq', 'arteria_umbilical_menor', 'arteria_cerebral_menor',
        'arteria_uraina_mayor', 'arteria_uraina_mayor_izq', 'arteria_umbilical_mayor', 'arteria_cerebral_mayor',
        'relacion_ud', 'relacion_ui', 'relacion_u', 'relacion_cm',
        'pico_ud', 'pico_ui', 'pico_u', 'pico_cm',
        'ductos_venosos', 'ven_umbilical', 'relac_aorta', 'sugerencias', 'conclusion',
    ];

    protected $casts = [
        'historia' => 'integer',
        'consulta' => 'integer',
        'gesta' => 'integer',
        'fur' => 'date',
        'fecha' => 'date',
    ];
}
