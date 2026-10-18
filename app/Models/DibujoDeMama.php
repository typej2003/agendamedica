<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El trazo de color sobre la imagen de una mama, de una consulta (WEB-11.10). Tabla nueva de AppDDR. */
class DibujoDeMama extends Model
{
    public const DERECHA = 'derecha';
    public const IZQUIERDA = 'izquierda';
    public const LADOS = [self::DERECHA, self::IZQUIERDA];

    protected $table = 'dibujos_de_mamas';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'lado', 'archivo'];
}
