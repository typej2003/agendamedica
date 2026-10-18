<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Los datos de la orden de laboratorio de la pareja de una consulta (WEB-11.21): la C.I., el nombre y el **resumen clínico** del diálogo
 * «Examen de la Pareja». Tabla **nueva de AppDDR** (una fila por consulta): el escritorio guarda la C.I. y el nombre repetidos en cada
 * examen (`examen_pareja`) y comparte el resumen con el de la paciente (`examen_obs`); acá la orden se recarga entera aunque todavía no
 * tenga exámenes y el resumen de la pareja no pisa el de la paciente.
 */
class ExamenParejaDatos extends Model
{
    protected $table = 'examen_pareja_datos';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'cedula', 'nombre', 'observacion'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer'];
}
