<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El trazo de color sobre la imagen del cuello uterino, de una consulta (A-13): **una fila por variante** con su
 * archivo (PNG transparente en el disco privado) y la **imagen base** elegida. Tabla nueva de AppDDR.
 *
 * Las dos variantes son las dos que traía la imagen del escritorio: la **nulípara** (orificio transverso) y la
 * **multípara** (orificio redondo). Las dos bases son la **vectorial** (la de la web, la que se ve por defecto) y la
 * **del escritorio** (la imagen escalada que usaba el PowerBuilder).
 */
class DibujoDeColposcopia extends Model
{
    public const NULIPARA = 'nulipara';
    public const MULTIPARA = 'multipara';
    public const VARIANTES = [self::NULIPARA, self::MULTIPARA];

    public const VECTOR = 'vector';
    public const ESCRITORIO = 'escritorio';
    public const BASES = [self::VECTOR, self::ESCRITORIO];

    /** La base que se pinta sin que el médico elija (la vectorial: la nueva y limpia). */
    public const BASE_POR_DEFECTO = self::VECTOR;

    protected $table = 'dibujos_de_colposcopia';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'variante', 'base', 'archivo'];
}
