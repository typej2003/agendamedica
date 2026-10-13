<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El eco obstétrico del primer trimestre** (`eco_obstetrico`, WEB-6.2): la ecografía del embarazo temprano.
 *
 * Es lo que la wiki describe como *Eco Obstétrico 1er Trimestre*: la FUR, la EG, el **saco gestacional** (y su
 * forma: circular, alargado, retraído), el **huevo anembrionado**, la **mola**, los **restos abortivos**, el
 * **embarazo ectópico**, el movimiento embrionario y la actividad cardíaca, la **LCC**, el **DBP**, el **saco
 * vitelino** (`sa_vit`), la **translucencia nucal** (`tn`/`t_nucal`) y el **ductus venoso** (`dc_venoso`).
 *
 * ⚠️ **La clave es `historia` + `consulta`** (`consulta` es `NOT NULL` en el esquema): **una fila por
 * consulta**, como el eco pélvico (`EcoPelvico`), no una por gesta. Las columnas `gesta`, `fur` y `eg` son
 * datos que el escritorio copia o teclea (el número de gesta que se estaba siguiendo, la FUR del control
 * prenatal y la edad gestacional como texto): **no** son la clave.
 *
 * ⚠️ **No confundir con las variantes**: `eco_obstetrico_tercer` (y `_tercer_2`) es el **tercer trimestre**
 * —otra tabla, 69 columnas—; `eco_obstetrico_tercer_o` y `_tercer_2_o` son sus variantes sin `puro_texto` ni
 * `gemelo` (vacías en el dump). El **Doppler** (`eco_doppler`) y el **ecocardiograma fetal**
 * (`ecocadiograma_fetal`) son otra tarea (WEB-6.3).
 */
class EcoObstetrico extends Model
{
    use HasFactory;

    protected $table = 'eco_obstetrico';

    /** La tabla entera, como en los demás modelos del legado: qué **edita** la web lo dice su Action. */
    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'gesta', 'fur', 'eg', 'fecha',
        'saco_gestacional', 'circular_ovuide', 'alargado', 'retraido', 'anormal',
        'movimiento_embrionario', 'actividad_cardiaca', 'lcc', 'dbp',
        'huevo_anembrionado', 'mola', 'restos_abortivos', 'embarazo_ectopico',
        'otros', 'observacion', 'dianostico', 'tn', 'posicionutreo', 'cuerpo', 'transverso', 'antero',
        'ovarioderlong', 'ovarioderap', 'ovariodertras', 'ovarioizqlong', 'ovarioizqap', 'ovarioizqtras',
        'cuerpo_utero', 'der', 'izqu', 'lcr', 'sa_vit', 't_nucal', 'dc_venoso', 'para',
        'puro_texto', 'posicionavf_d', 'posicionrvf_d',
    ];

    protected $casts = [
        'historia' => 'integer',
        'consulta' => 'integer',
        'gesta' => 'integer',
    ];
}
