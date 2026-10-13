<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **El eco pélvico de una consulta** (`eco_pelvico`, WEB-6.1).
 *
 * Es la tabla del legado donde el escritorio guarda el **eco ginecológico** (ventana
 * `w_cargar_visualizar_ecografia`, DataWindow `d_eco_pelvico`).
 *
 * ⚠️ **La clave del legado es `(numhistoria, nroconsulta)`** —los números de la historia y de la consulta,
 * no ids—: el `key=yes` está en esas dos columnas del DataWindow (`d_eco_pelvico.srd:6` y `:19`) y su
 * `retrieve` cruza por las dos (`:48`). El escritorio crea la fila con
 * `dw_1.InsertRow(0)` + `numhistoria` + `fecha = today()` + `nroconsulta`
 * (`w_cargar_visualizar_ecografia.srw:216-224`) y la vuelve a guardar con `dw_1.Update()` (`:539`, `:562`,
 * `:815`, `:832`). **Una fila por consulta**, no un conjunto. Fíjate que las columnas se llaman
 * `numhistoria`/`nroconsulta`, no `historia`/`consulta` como en `examen_fisico_nuevo`.
 *
 * `PARA` es el nombre real de la columna, en mayúsculas, tal como quedó migrada: no se renombra (regla de
 * la wiki `07-modelo-de-datos.md`).
 *
 * Las columnas `centrar`, `cuello`, `anterocuello`, `formautero` y `puro_texto` **están en la tabla pero
 * no las escribe esta pantalla** (el DataWindow no las muestra): el detalle de quién las usa, en
 * `App\Clinica\Consulta\EcoGinecologico::columnasQueNoSeTocan()`.
 */
class EcoPelvico extends Model
{
    use HasFactory;

    protected $table = 'eco_pelvico';

    /**
     * Las columnas de la tabla. **No es la lista de lo que la web edita**: eso lo dice
     * `App\Clinica\Consulta\EcoGinecologico::columnas()`. Acá está todo lo que la tabla tiene —incluidas
     * las que esta pantalla no escribe—, porque el modelo describe la tabla del legado y cualquier capa
     * (el sync, un test, una tarea futura) tiene que poder escribirla.
     */
    protected $fillable = [
        'reg_medico',
        'numhistoria',
        'nroconsulta',
        'fecha',
        'fur',
        'gesta',
        'PARA',
        'trans_convex',
        'trans_transvaginal',
        'vagina',
        'vagina_llena',
        'posicionavf',
        'posicionavf_d',
        'posicionrvf_d',
        'fondosacolibre',
        'bordeutero',
        'patron',
        'cuerpo',
        'transverso',
        'antero',
        'linea',
        'linea_continuo',
        'linea_homo',
        'linea_cavidad_u',
        'espesor',
        'fondosacootro',
        'observacion',
        'ovarioderlong',
        'ovarioderap',
        'ovariodertras',
        'volumen_der',
        'ovarioizqlong',
        'ovarioizqap',
        'ovarioizqtras',
        'volumen_izq',
        'observacionovario',
        'idxecografico',
        'sugerencias',
        // Columnas que la pantalla del eco pélvico no toca (ver el docblock de la clase).
        'centrar',
        'cuello',
        'anterocuello',
        'formautero',
        'puro_texto',
    ];

    /**
     * Los números de la clave son enteros y las medidas son `decimal(5,2)`: se castean para que el
     * formulario y el informe reciban números y no cadenas como `"60.00"`.
     *
     * Las dos fechas (`fecha` y `fur`) **no** se castean a propósito: se leen y se escriben como el
     * `AAAA-MM-DD` del `<input type="date">`, que es exactamente lo que guarda la columna.
     */
    protected $casts = [
        'numhistoria' => 'integer',
        'nroconsulta' => 'integer',
        'gesta' => 'integer',
        'cuerpo' => 'float',
        'antero' => 'float',
        'transverso' => 'float',
        'espesor' => 'float',
        'cuello' => 'float',
        'anterocuello' => 'float',
        'ovarioderlong' => 'float',
        'ovarioderap' => 'float',
        'ovariodertras' => 'float',
        'ovarioizqlong' => 'float',
        'ovarioizqap' => 'float',
        'ovarioizqtras' => 'float',
        'volumen_der' => 'float',
        'volumen_izq' => 'float',
    ];
}
