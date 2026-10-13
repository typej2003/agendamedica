<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **Plantilla de texto del médico** — tabla legada `formato_print` (WEB-8.4): un `titulo` y un texto (`descripcion`) de un `tipo`
 * (`EV` evolución, `IM` informe médico, `DI` dietas / recomendaciones…), que la consulta ofrece para copiar al campo que se escribe.
 * `codigo` es el correlativo de cuatro dígitos del médico (`reg_medico`). Ver `App\Clinica\Catalogos\TiposDePlantillaDeTexto`.
 */
class FormatoPrint extends Model
{
    protected $table = 'formato_print';

    protected $fillable = ['reg_medico', 'codigo', 'titulo', 'descripcion', 'tipo'];
}
