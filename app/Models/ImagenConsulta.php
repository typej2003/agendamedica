<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una **imagen clínica de una consulta** (`imagen_consulta`, WEB-4.7).
 *
 * La fila cuelga de la consulta: `nrohistoria` + `nroconsulta`, las dos `NOT NULL` en la migración núcleo
 * (`2026_08_25_055351_create_medical_tables_schema.php:1213`), que son la misma llave de negocio que usa
 * `consultas` (no su `id`). Así la escribe el escritorio al cargar una imagen
 * (`w_consulta_principal.srw:10744`).
 *
 * Dos formas de fila conviven y **las dos son válidas**. `disco` es la marca de origen:
 *
 *  - **La del escritorio** (`disco` nulo): `imagen` guarda la **ruta absoluta del disco de la PC del
 *    consultorio** (`C:\…\20211025_121548.jpg`). El archivo no está en el servidor; copiarlo a la nube es
 *    un proceso aparte (WEB-T.1).
 *  - **La de la web** (`disco` con el disco de Laravel, hoy `clinica`): `imagen` guarda la **ruta relativa
 *    al disco privado** (`examenes/<reg_medico>/<año-mes>/<aleatorio>.<ext>`), que es lo que la columna
 *    del legado quiere decir y lo que le permite al escritorio mostrar el archivo de esa consulta.
 *
 * El modelo no decide nada de eso: el mapa del dato y el porqué están en
 * `App\Clinica\Consulta\ImagenesDeLaConsulta` y en `App\Clinica\Archivos\ArchivosClinicos`.
 */
class ImagenConsulta extends Model
{
    protected $table = 'imagen_consulta';

    protected $fillable = [
        'reg_medico',
        'nrohistoria',
        'nroconsulta',
        'observacion',
        'imagen',
        'disco',
        'orden',
    ];

    protected $casts = [
        'nrohistoria' => 'integer',
        'nroconsulta' => 'integer',
        'orden' => 'integer',
    ];
}
