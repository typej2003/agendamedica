<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El ecocardiograma fetal** (`ecocadiograma_fetal`, WEB-6.3): el estudio cardíaco del feto.
 *
 * ⚠️ **La tabla se llama con el error de tipeo del legado** —`ecocadiograma_fetal`, sin la «r»— y así está en
 * el DataWindow, en el dump y en la migración de AppDDR: **no se renombra** (regla 2 de `AGENTS.md`). El
 * modelo sí se llama bien. Es la tabla que la especialidad declara **propia de ginecología**
 * (`config/especialidades/ginecologia.php:57`), a diferencia de `ecocardiografia`, `ecocardiograma_ete` y
 * `ecocardiografico_in`, que son de cardiología (trampa 124).
 *
 * Lo abre `open(w_cargar_visualizar_ecocardiograma_fetal)` (`w_consulta_principal.srw:20477`) y su DataWindow
 * vivo es `d_ecocardiograma_fetal` (en `fuentes/ventana_consulta/`, no en `datawin_procesos/`).
 *
 * ⚠️ **La clave es `historia` + `consulta`** (`consulta` es `NOT NULL`): **una fila por consulta**.
 *
 * Su ventana **precarga dos textos** al abrir una consulta sin fila (`texto_inicio` y `anatomi_datos`) y la
 * fecha de hoy: es comportamiento del escritorio, no sólo datos. Sus fotos van a `imagen_consulta` con
 * `observacion = 'f'` (`TagDeImagen::FETAL`).
 *
 * Las columnas salen del DataWindow vivo y de la migración (relevado el 2026-10-14).
 */
class EcoCardiogramaFetal extends Model
{
    use HasFactory;

    protected $table = 'ecocadiograma_fetal';

    /** La tabla entera, como en los demás modelos del legado: qué **edita** la web lo dice su Action. */
    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'fecha', 'fur', 'gesta', 'cesarea', 'parto', 'aborto', 'eg',
        'referido', 'otros',
        // Datos biométricos (todos en milímetros salvo la relación, que el escritorio escribe como texto).
        'biome_dbp', 'biome_dof', 'biome_cc', 'biome_relacion', 'biome_cardiaca', 'biome_toraxica',
        'biome_ca', 'biome_humero', 'biome_femur', 'biome_saco',
        // Datos anatómicos y placento-funiculares.
        'anatomi_datos', 'texto_inicio',
        'pla_funi_placenta', 'pla_funi_grado', 'pla_funi_grosor', 'pla_funi_cordon_umb',
        'pla_funi_liquido_anm', 'pla_funi_pef', 'pla_funi_sexo',
        'conclusion', 'sugerencia',
    ];

    protected $casts = [
        'historia' => 'integer',
        'consulta' => 'integer',
        'fecha' => 'date',
        'fur' => 'date',
        'gesta' => 'integer',
        'cesarea' => 'integer',
        'parto' => 'integer',
        'aborto' => 'integer',
        'eg' => 'integer',
        'referido' => 'integer',
        'biome_dbp' => 'integer',
        'biome_dof' => 'integer',
        'biome_cc' => 'integer',
        'biome_relacion' => 'integer',
        'biome_cardiaca' => 'integer',
        'biome_toraxica' => 'integer',
        'biome_ca' => 'integer',
        'biome_humero' => 'integer',
        'biome_femur' => 'integer',
        'biome_saco' => 'integer',
        'pla_funi_grosor' => 'integer',
        'pla_funi_pef' => 'integer',
    ];
}
