<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **Un laboratorio del embarazo** (`prena_exames_b`, WEB-6.6): una fila por **fecha** de laboratorio dentro
 * de una gesta.
 *
 * ⚠️ **No es la tabla que parece**: el laboratorio de la pestaña viva del control prenatal es esta
 * (`d_pacientes_exa_b.srd`, asignado en `w_consulta_principal.srw:24920`), **no** `pre_natal_examenes`, que
 * es la solapa vieja y oculta y —en el dump— **no tiene un solo dato clínico** (11.389 filas con sólo
 * historia y fecha). Mismo caso que la trampa 99 de `TRAMPAS.md`.
 *
 * Clave: **`historia` + `fecha` + `gesta_clave`** (`d_pacientes_exa_b.srd:6-8`, las tres `key=yes`; en el
 * legado hay `CREATE UNIQUE INDEX primarykey ON prena_exames_b (historia, fecha, gesta_clave)`,
 * `f_agregar_datos.srf:1593`). `gesta_clave` es el número de gesta (1..15) y es lo único que ata esta tabla
 * a un embarazo.
 */
class PrenaExamenB extends Model
{
    use HasFactory;

    protected $table = 'prena_exames_b';

    /** Igual que en las otras tablas del embarazo: la tabla entera, no sólo lo que la web edita. */
    protected $fillable = [
        'reg_medico', 'historia', 'fecha', 'gesta_clave',
        // Lo que muestra la pestaña Laboratorio (los rótulos, en `campos()`).
        'hemoglobina', 'hematoc', 'glob_blanco', 'neut_linf', 'triglicer', 'colesterol', 'glicemia_basal',
        'ac_urico', 'progester', 'prolactina', 'urea', 'creatinina', 'orina', 'heces', 'otros',
        'hiv', 'vdrl', 'toxo_igm', 'toxo_igg',
        // Columnas que el DataWindow tiene ocultas y el escritorio persiste igual (ver `campos()`).
        'vsg', 'plaquetas', 'glicemia_post_prandial', 'insulina_basal', 'insulina_post_prandial',
        'hdl_colest', 'ldl_colest', 'urocultivo', 't3', 't4', 'tsh', 'fsh', 'lh', 'estradiol',
    ];

    protected $casts = [
        'historia' => 'integer',
        'gesta_clave' => 'integer',
    ];

    /**
     * **El mapa de los campos del laboratorio**: qué columna, con qué rótulo y con qué control.
     *
     * Los rótulos son los del DataWindow de la pestaña (`d_pacientes_exa_b.srd:43-61`), abreviados como los
     * muestra el escritorio (*Hb.*, *Hto*, *Glob. Blancos*…), salvo una excepción deliberada: **`progester`
     * y `prolactina`**. El `.srd` les pone los rótulos **"PT"** y **"PTT"** (`:51` y `:52`) porque reusa el
     * layout del examen viejo, pero PT/PTT son pruebas de coagulación y estas columnas son **progesterona y
     * prolactina**: la web las rotula por lo que la columna guarda y no por el rótulo heredado —quien mire
     * los datos del escritorio verá PT/PTT, y esa diferencia está anotada como duda en la wiki—.
     *
     * El tipo `opciones` es para los cuatro que el escritorio pinta como casillas con el resultado
     * (`hiv`, `vdrl`, `toxo_igm`, `toxo_igg`): la fuente no declara sus valores posibles, así que se
     * ofrecen *Positivo*, *Negativo* y *No reactivo* tal cual se escriben en un laboratorio.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campos(): array
    {
        $reactivo = ['tipo' => 'opciones', 'ayuda' => 'El resultado del laboratorio.',
            'opciones' => [
                ['valor' => 'Negativo', 'texto' => 'Negativo'],
                ['valor' => 'Positivo', 'texto' => 'Positivo'],
                ['valor' => 'No reactivo', 'texto' => 'No reactivo'],
            ]];

        return [
            ['columna' => 'hemoglobina', 'etiqueta' => 'Hb.', 'tipo' => 'numero', 'ayuda' => 'Hemoglobina.'],
            ['columna' => 'hematoc', 'etiqueta' => 'Hto', 'tipo' => 'numero', 'ayuda' => 'Hematocrito.'],
            ['columna' => 'glob_blanco', 'etiqueta' => 'Glob. Blancos', 'tipo' => 'numero', 'ayuda' => 'Glóbulos blancos.'],
            ['columna' => 'neut_linf', 'etiqueta' => 'Linf.', 'tipo' => 'numero', 'ayuda' => 'Neutrófilos y linfocitos (el escritorio lo muestra con «%»).'],
            ['columna' => 'triglicer', 'etiqueta' => 'Trig.', 'tipo' => 'numero', 'ayuda' => 'Triglicéridos.'],
            ['columna' => 'colesterol', 'etiqueta' => 'Coles', 'tipo' => 'numero', 'ayuda' => 'Colesterol.'],
            ['columna' => 'glicemia_basal', 'etiqueta' => 'Glice.', 'tipo' => 'numero', 'ayuda' => 'Glicemia basal.'],
            ['columna' => 'ac_urico', 'etiqueta' => 'Ac.U.', 'tipo' => 'numero', 'ayuda' => 'Ácido úrico.'],
            ['columna' => 'progester', 'etiqueta' => 'Progesterona', 'tipo' => 'numero',
                'ayuda' => 'El escritorio le pone el rótulo «PT», que no es lo que la columna guarda.'],
            ['columna' => 'prolactina', 'etiqueta' => 'Prolactina', 'tipo' => 'numero',
                'ayuda' => 'El escritorio le pone el rótulo «PTT», que no es lo que la columna guarda.'],
            ['columna' => 'urea', 'etiqueta' => 'Urea', 'tipo' => 'numero', 'ayuda' => 'Urea.'],
            ['columna' => 'creatinina', 'etiqueta' => 'Crea.', 'tipo' => 'numero', 'ayuda' => 'Creatinina.'],
            ['columna' => 'orina', 'etiqueta' => 'Orina', 'tipo' => 'texto', 'ayuda' => 'El resultado del examen de orina.'],
            ['columna' => 'heces', 'etiqueta' => 'Heces', 'tipo' => 'texto', 'ayuda' => 'El resultado del examen de heces.'],
            ['columna' => 'hiv', 'etiqueta' => 'HIV', 'ayuda' => 'VIH.'] + $reactivo,
            ['columna' => 'vdrl', 'etiqueta' => 'VDRL', 'ayuda' => 'VDRL.'] + $reactivo,
            ['columna' => 'toxo_igm', 'etiqueta' => 'Toxo IgM', 'ayuda' => 'Toxoplasmosis IgM.'] + $reactivo,
            ['columna' => 'toxo_igg', 'etiqueta' => 'Toxo IgG', 'ayuda' => 'Toxoplasmosis IgG.'] + $reactivo,
            ['columna' => 'otros', 'etiqueta' => 'Otros', 'tipo' => 'area', 'ayuda' => 'Lo que no entra en las otras casillas.'],
        ];
    }

    /**
     * Las columnas de esta tabla que la pestaña **no** edita: el escritorio las tiene **ocultas** en el
     * DataWindow (`visible="0"`, `d_pacientes_exa_b.srd:81-94`) aunque las persista. La web no las toca: si
     * alguna vez hacen falta (plaquetas, TSH, insulina…), se agregan como una sección de «más datos» y se
     * sacan de esta lista.
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_SE_TOCAN = [
        'vsg', 'plaquetas', 'glicemia_post_prandial', 'insulina_basal', 'insulina_post_prandial',
        'hdl_colest', 'ldl_colest', 'urocultivo', 't3', 't4', 'tsh', 'fsh', 'lh', 'estradiol',
    ];
}
