<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una dosis de vacuna de un niño (`vacuna_paciente`, tabla del legado de pediatría). La clave del escritorio es `historia` + `codi_vacuna` +
 * `fecha_ejecutada`. `ejecutada` = `S` es una dosis **aplicada**; el escritorio también guarda ahí las planeadas con `N` y, a esas,
 * les escribe en `fecha_ejecutada` el día en que cargó la fila: **la fecha no dice si la dosis se aplicó, lo dice `ejecutada`**
 * (Alexander, 2026-11-06). `fecha_planeada` es la «F. a aplicar» y `fecha_proxima_dosis`, el recordatorio de la próxima dosis de
 * la misma vacuna, que se llena al aplicarla (`fecha_proxima_vac`, en cambio, no tiene semántica confirmada: no se usa).
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
