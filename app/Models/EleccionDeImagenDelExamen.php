<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **Cuál de las dos imágenes de un examen es la del estudio** (O-09): una fila por consulta y examen que dice el **modo**
 * elegido con el radiobutón —`esquema` (la imagen predeterminada) o `foto` (el videocolposcopio / videohisteroscopio)— y,
 * cuando hay más de una foto, **cuál** de ellas se está marcando (`imagen_id`).
 *
 * La fila no es un adorno: la elección **decide qué se imprime** (lo que no se eligió no sale aunque exista), así que no
 * se puede deducir del estado cada vez: un estudio con foto y con dibujo tiene que recordar cuál eligió el médico.
 * Cuando no hay fila todavía, el modo se resuelve por lo que el estudio ya tiene (foto si hay foto, esquema si no).
 *
 * Tabla nueva de AppDDR, sin sync.
 */
class EleccionDeImagenDelExamen extends Model
{
    protected $table = 'elecciones_de_imagen_del_examen';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'examen', 'modo', 'imagen_id'];
}
