<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **El trazo de la imagen de un examen** (A-13, generalizado en O-09): el dibujo de color que el médico marca sobre la
 * **imagen predeterminada** (el esquema) o sobre la **foto real** del estudio. Tabla nueva de AppDDR.
 *
 * Una fila por *lienzo*, y el lienzo lo dice `clave` (ver `App\Clinica\Consulta\ImagenDelExamen`):
 *
 *  - `esquema:<variante>` — el esquema del examen (`nulipara`, `multipara`, `utero`), con su **imagen base** elegida
 *    (`vector` o `escritorio`) cuando el esquema la ofrece;
 *  - `foto:<id>` — la foto del estudio (`imagen_consulta.id`), que queda **referenciada** en `imagen_id`: la foto no se
 *    copia, y el trazo se va con ella si la foto se quita.
 *
 * Se guarda **sólo el trazo** —un PNG transparente en el disco privado `clinica`—, así cambiar la imagen de abajo no lo
 * pierde y la hoja impresa lo compone sin arrastrar fondo.
 */
class DibujoDelExamen extends Model
{
    protected $table = 'dibujos_del_examen';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'examen', 'clave', 'imagen_id', 'variante', 'base', 'archivo'];
}
