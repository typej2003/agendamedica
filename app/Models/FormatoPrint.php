<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **Plantilla de texto del médico** — tabla legada `formato_print` (WEB-8.4): un `titulo` y un texto (`descripcion`) de un `tipo`
 * (`EV` evolución, `IM` informe médico, `DI` dietas / recomendaciones…), que la consulta ofrece para copiar al campo que se escribe.
 * `codigo` es el correlativo de cuatro dígitos del médico (`reg_medico`). Ver `App\Clinica\Catalogos\TiposDePlantillaDeTexto`.
 *
 * Desde O-17 la misma tabla guarda también las **plantillas del formato** de los estudios ecográficos: en esas filas la columna
 * `formato` (nullable) lleva la clave del estudio y `descripcion` los campos descriptivos en JSON, con `tipo` en `NULL`. Una fila
 * con `formato` es una plantilla del formato; una sin él, una plantilla de texto de siempre. Ver
 * `App\Clinica\Consulta\PlantillasDelFormato`.
 */
class FormatoPrint extends Model
{
    protected $table = 'formato_print';

    protected $fillable = ['reg_medico', 'codigo', 'titulo', 'descripcion', 'tipo', 'formato'];
}
