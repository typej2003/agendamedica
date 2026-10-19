<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`endosc_diges_super`**: la endoscopía digestiva superior de una consulta (gastroenterología pediátrica).
 *
 * La clave del DataWindow del escritorio es `historia` + `consulta` (una por consulta); la web agrega `id` y
 * `reg_medico`. El mapa de los campos está en `App\Clinica\Consulta\EndoscopiaDigestiva`.
 */
class EndoscopiaSuperior extends Model
{
    protected $table = 'endosc_diges_super';

    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'fecha', 'equipo', 'indicacion', 'sedacion', 'descripcion',
        'conclusiones', 'full_texto',
    ];

    protected $casts = ['fecha' => 'date', 'historia' => 'integer', 'consulta' => 'integer'];
}
