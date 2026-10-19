<?php

namespace App\Models;

use App\Clinica\Embarazo\Gesta;
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
     * **Los cuatro reactivos del laboratorio y el código de una letra que guarda el escritorio**
     * (columna => [código => rótulo]).
     *
     * Las cuatro columnas son `VARCHAR(1)` en MySQL (`prena_exames_b.hiv`, `vdrl`, `toxo_igm` y `toxo_igg`,
     * ver el dump en `Docs/MICONSULTAGI-legado/archivos sql/export_parte_11.sql`) y el DataWindow guarda
     * **un código**, no la palabra: sus `values` son `"+  P/-  N/"` en `hiv`, `"R  R/N  N/"` en `vdrl` y
     * `"S  S/N  N/"` en las dos toxo (`d_pacientes_exa_b.srd:26`, `:27`, `:39` y `:40`); lo que va a la
     * columna es la **segunda** mitad de cada par y lo de la izquierda es lo que se ve en el escritorio.
     *
     * ⚠️ **Guardar el rótulo entero revienta en MySQL, no en SQLite.** La web escribía «Negativo» en estas
     * columnas y en producción —que es MySQL en modo estricto— el `INSERT` moría con
     * `SQLSTATE[22001] … 1406 Data too long for column 'hiv'`; en SQLite, que no mira el largo, el mismo
     * guardado pasaba sin ruido y los tests no lo veían. Es la trampa 140 de `TRAMPAS.md`.
     */
    public const REACTIVOS = [
        'hiv' => ['P' => 'Positivo', 'N' => 'Negativo'],
        'vdrl' => ['R' => 'Reactivo', 'N' => 'No reactivo'],
        'toxo_igm' => ['S' => 'Positivo', 'N' => 'Negativo'],
        'toxo_igg' => ['S' => 'Positivo', 'N' => 'Negativo'],
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
     * **El orden y el agrupamiento son los de la grilla del escritorio** (O-21, captura
     * `21-control-de-embarazo-laboratorio.png`): un primer renglón con el panel del embarazo
     * (*Hb.* … *Toxo IgG*) y, aparte, los cinco que el DataWindow pone en su segundo renglón
     * (*Urea · Crea. · Orina · Heces · Otros*), que es el `bloque` `otros` con su rótulo. La web los muestra
     * como el formulario de **una fecha** (las fechas son los chips de la pestaña) y no inventa una grilla.
     *
     * El tipo `opciones` es para los cuatro que el escritorio pinta como casillas con el resultado
     * (`hiv`, `vdrl`, `toxo_igm`, `toxo_igg`): **el valor de cada opción es el código de una letra** que va a
     * la columna (`N`, `P`, `R`, `S`; ver `REACTIVOS`) y el texto es lo que se lee. La fuente declara esos
     * códigos en el `values` de cada campo, así que no se inventan.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campos(): array
    {
        // Un reactivo: el desplegable con los códigos del escritorio (no el rótulo, que no entra en un
        // `char(1)`).
        $reactivo = fn (string $columna) => ['tipo' => 'opciones', 'ayuda' => 'El resultado del laboratorio.',
            'opciones' => self::opcionesDelReactivo($columna)];

        // Los dos renglones del DataWindow: su panel de exámenes y «Otros estudios».
        $panel = ['bloque' => 'panel', 'ancho' => 'col-6 col-md-2'];
        $otros = ['bloque' => 'otros', 'rotulo' => 'Otros estudios'];

        // Un número del laboratorio: **la columna del escritorio es `char(5)`**, así que su tope de
        // validación es 5 y no el genérico de 20 (un valor más largo también desborda el `INSERT` en MySQL,
        // trampa 140). `numero()` de la Action deja en blanco lo que no es número.
        $numero = ['tipo' => 'numero', 'max' => 5];

        return [
            ['columna' => 'hemoglobina', 'etiqueta' => 'Hb.', 'ayuda' => 'Hemoglobina.'] + $numero + $panel,
            ['columna' => 'hematoc', 'etiqueta' => 'Hto', 'ayuda' => 'Hematocrito.'] + $numero + $panel,
            ['columna' => 'glob_blanco', 'etiqueta' => 'Glob. Blancos', 'ayuda' => 'Glóbulos blancos.'] + $numero + $panel,
            ['columna' => 'neut_linf', 'etiqueta' => 'Linf.', 'ayuda' => 'Neutrófilos y linfocitos (%).'] + $numero + $panel,
            ['columna' => 'triglicer', 'etiqueta' => 'Trig.', 'ayuda' => 'Triglicéridos.'] + $numero + $panel,
            ['columna' => 'colesterol', 'etiqueta' => 'Coles', 'ayuda' => 'Colesterol.'] + $numero + $panel,
            ['columna' => 'glicemia_basal', 'etiqueta' => 'Glice.', 'ayuda' => 'Glicemia basal.'] + $numero + $panel,
            ['columna' => 'ac_urico', 'etiqueta' => 'Ac.U.', 'ayuda' => 'Ácido úrico.'] + $numero + $panel,
            ['columna' => 'progester', 'etiqueta' => 'Progesterona', 'ayuda' => 'El escritorio la rotula «PT».'] + $numero + $panel,
            ['columna' => 'prolactina', 'etiqueta' => 'Prolactina', 'ayuda' => 'El escritorio la rotula «PTT».'] + $numero + $panel,
            ['columna' => 'hiv', 'etiqueta' => 'HIV', 'ayuda' => 'VIH.'] + $reactivo('hiv') + $panel,
            ['columna' => 'vdrl', 'etiqueta' => 'VDRL', 'ayuda' => 'VDRL.'] + $reactivo('vdrl') + $panel,
            ['columna' => 'toxo_igm', 'etiqueta' => 'Toxo IgM', 'ayuda' => 'Toxoplasmosis IgM.'] + $reactivo('toxo_igm') + $panel,
            ['columna' => 'toxo_igg', 'etiqueta' => 'Toxo IgG', 'ayuda' => 'Toxoplasmosis IgG.'] + $reactivo('toxo_igg') + $panel,
            ['columna' => 'urea', 'etiqueta' => 'Urea', 'ayuda' => 'Urea.', 'ancho' => 'col-6 col-md-2'] + $numero + $otros,
            ['columna' => 'creatinina', 'etiqueta' => 'Crea.', 'ayuda' => 'Creatinina.', 'ancho' => 'col-6 col-md-2'] + $numero + $otros,
            ['columna' => 'orina', 'etiqueta' => 'Orina', 'tipo' => 'texto',
                'ayuda' => 'El resultado del examen de orina.', 'ancho' => 'col-6 col-md-2'] + $otros,
            ['columna' => 'heces', 'etiqueta' => 'Heces', 'tipo' => 'texto',
                'ayuda' => 'El resultado del examen de heces.', 'ancho' => 'col-6 col-md-3'] + $otros,
            ['columna' => 'otros', 'etiqueta' => 'Otros', 'tipo' => 'area',
                'ayuda' => 'Lo que no entra en las otras casillas.', 'ancho' => 'col-12 col-md-3'] + $otros,
        ];
    }

    /**
     * ¿Es uno de los cuatro reactivos (los que se guardan con un código de una letra)?
     */
    public static function esReactivo(string $columna): bool
    {
        return array_key_exists($columna, self::REACTIVOS);
    }

    /**
     * **El valor que va a la columna de un reactivo**: su código de una letra.
     *
     * Acepta el código (`N`, `P`, `R`, `S`) y también el rótulo (`Negativo`, `No reactivo`…), porque la web
     * guardó los rótulos hasta la trampa 140 y un formulario abierto antes del arreglo los sigue mandando.
     * Un código de una letra que no esté en la lista del escritorio **se conserva** (puede venir de una fila
     * del escritorio y no se inventa una traducción); cualquier otra cosa se guarda en blanco: no entra en
     * la columna y guardarla revienta el `INSERT` en MySQL.
     *
     * @param  mixed  $crudo  lo que llegó del formulario
     */
    public static function codigoDe(string $columna, $crudo): ?string
    {
        $texto = Gesta::texto($crudo);

        if ($texto === null) {
            return null;
        }

        $reactivos = self::REACTIVOS[$columna] ?? [];

        if (isset($reactivos[$texto])) {
            return $texto;
        }

        foreach ($reactivos as $codigo => $rotulo) {
            if (strcasecmp($rotulo, $texto) === 0) {
                return $codigo;
            }
        }

        return mb_strlen($texto) === 1 ? $texto : null;
    }

    /**
     * **Lo que se muestra** de un reactivo: el rótulo de su código. Lo que ya está escrito como rótulo se
     * devuelve tal cual (las filas que la web guardó antes de la trampa 140) y un código desconocido del
     * escritorio se muestra como está. En las demás columnas no cambia nada.
     */
    public static function textoDe(string $columna, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return $valor;
        }

        $reactivos = self::REACTIVOS[$columna] ?? [];

        if (isset($reactivos[$valor])) {
            return $reactivos[$valor];
        }

        foreach ($reactivos as $rotulo) {
            if (strcasecmp($rotulo, $valor) === 0) {
                return $rotulo;
            }
        }

        return $valor;
    }

    /**
     * Las opciones del desplegable de un reactivo: **la clave es el código** que va a la columna y el texto
     * es el rótulo (como `PreNatalDesarrolloFino::presentacion`).
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    private static function opcionesDelReactivo(string $columna): array
    {
        $opciones = [];

        foreach (self::REACTIVOS[$columna] as $codigo => $rotulo) {
            $opciones[] = ['valor' => $codigo, 'texto' => $rotulo];
        }

        return $opciones;
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
