<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una dosis de vacuna de un niño (`vacuna_paciente`, tabla del legado de pediatría). La clave del escritorio es `historia` + `codi_vacuna` +
 * `fecha_ejecutada`. `ejecutada` = `S` es una dosis **aplicada**; el escritorio también guarda ahí las planeadas con `N`.
 */
class VacunaPaciente extends Model
{
    protected $table = 'vacuna_paciente';

    protected $fillable = [
        'reg_medico', 'historia', 'codi_vacuna', 'dosis', 'fecha_planeada', 'fecha_ejecutada', 'ejecutada', 'nota', 'descripcion',
        'fecha_proxima_dosis', 'dosis_numero', 'edad_aplicada', 'comentario', 'prioridad', 'fecha_vence', 'fecha_proxima_vac', 'lote',
        'via', 'zona', 'tipo_vacu', 'aplicada_aca',
    ];

    protected $casts = [
        'historia' => 'integer',
        'dosis_numero' => 'integer',
        'edad_aplicada' => 'float',
        'fecha_planeada' => 'date',
        'fecha_ejecutada' => 'date',
        'fecha_proxima_dosis' => 'date',
        'fecha_vence' => 'date',
    ];
}
