<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`endosc_diges_infer`**: la endoscopía digestiva inferior (colonoscopía) de una consulta (gastroenterología
 * pediátrica).
 *
 * ⚠️ La tabla del escritorio guarda **esófago, estómago y duodeno** con su estado y su descripción: es el mismo
 * juego de columnas de la superior (la tabla se copió en el legado). La web respeta las columnas tal como están.
 */
class EndoscopiaInferior extends Model
{
    protected $table = 'endosc_diges_infer';

    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'fecha', 'equipo', 'indicacion', 'sedacion',
        'esofago_estado', 'esofago_descripcion', 'estomago_estado', 'estomago_descripcion',
        'duodeno_estado', 'duodeno_descripcion', 'conclusiones', 'full_texto',
    ];

    protected $casts = ['fecha' => 'date', 'historia' => 'integer', 'consulta' => 'integer'];
}
