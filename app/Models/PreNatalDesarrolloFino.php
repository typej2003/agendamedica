<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * **Un control del embarazo** (`pre_natal_desarrollo_fino`, WEB-6.6): una fila por **fecha de control**
 * dentro de una gesta.
 *
 * ⚠️ **No es `pre_natal_desarrollo`**: la pestaña *Controles* viva usa esta tabla
 * (`d_pre_natal_rutin_eco_multiple.srd`, asignado en `w_consulta_principal.srw:25085`); la otra es la de la
 * solapa vieja y oculta, **sin `gesta_clave`** y —en el dump— con 6.848 filas y **ni un dato clínico**. Es
 * la misma trampa 99 que el laboratorio y la pestaña Prenatal. `_fino` es la versión con gesta de
 * `pre_natal_desarrollo` más las medidas ecográficas.
 *
 * Clave: **`historia` + `fecha` + `gesta_clave`** (`d_pre_natal_rutin_eco_multiple.srd:6`, `:7` y `:22`,
 * las tres `key=yes`; en el legado, `CREATE UNIQUE INDEX primarykey ON pre_natal_desarrollo_fino (historia,
 * fecha, gesta_clave)`, `f_agregar_datos.srf:1631`). La columna `consulta` existe pero **no es clave** y en
 * el dump está 100% NULL.
 *
 * **Dos columnas del escritorio que parecen otra cosa y no lo son** (verificadas contra los valores del
 * DataWindow, no contra su nombre):
 *
 *  - **`mf`**: en el escritorio es un desplegable rotulado **«Placenta»** cuyos valores son *Anterior*,
 *    *Posterior*, *Fúndica*, *Lateral Derecho*… O sea que el escritorio **reusa `mf`** (que en otras tablas
 *    son movimientos fetales) para la **posición de la placenta**. El rótulo es correcto para esta pantalla.
 *  - **`eg_fur` y `eg_eco`**: el médico las **teclea** (máscara `##s + #d`, o sea "20s + 6d"), no las
 *    calcula el DataWindow. La web puede **sugerir** la de la FUR desde la gesta, pero lo guardado es lo que
 *    el médico escribió.
 */
class PreNatalDesarrolloFino extends Model
{
    use HasFactory;

    protected $table = 'pre_natal_desarrollo_fino';

    protected $fillable = [
        'reg_medico', 'historia', 'fecha', 'gesta_clave', 'consulta',
        // Lo que muestra la pestaña Controles (los rótulos, en `campos()`).
        'peso', 'ta', 'au', 'mf', 'presentacion', 'edemas', 'dbp', 'femur', 'peso_fetal', 'foco_f',
        'eg_fur', 'eg_eco',
        // Columnas que el DataWindow tiene ocultas y el escritorio persiste igual.
        'lf', 'ac_cardiaca', 'la', 'sg_cri', 'plac_grado', 'lcc', 'dbpf', 'varice', 'multiple', 'cantidad',
    ];

    protected $casts = [
        'historia' => 'integer',
        'gesta_clave' => 'integer',
        'consulta' => 'integer',
    ];

    /**
     * **El mapa de los campos del control**: qué columna, con qué rótulo y con qué control.
     *
     * Los rótulos son los del DataWindow (`d_pre_natal_rutin_eco_multiple.srd:36-68`) y el orden es el de la
     * grilla del escritorio (O-21, captura `22-control-de-embarazo-controles.png`): *A.U. · Peso · T.A. ·
     * Presentación · Edemas · DBP · Placenta · Foco Fetal · Fémur · EG-FUR · EG-ECO · Peso F.*, de izquierda a
     * derecha. `presentacion`, `edemas` y `mf` (la **placenta**) son desplegables con los valores del `.srd`,
     * que no son inventados: son la lista que el médico ve.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campos(): array
    {
        return [
            ['columna' => 'au', 'etiqueta' => 'A.U.', 'tipo' => 'texto', 'max' => 5, 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'Altura uterina.'],
            ['columna' => 'peso', 'etiqueta' => 'Peso', 'tipo' => 'texto', 'max' => 5, 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'El peso de la madre.'],
            ['columna' => 'ta', 'etiqueta' => 'T.A.', 'tipo' => 'texto', 'max' => 7, 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'Tensión arterial, como «120/80».'],
            ['columna' => 'presentacion', 'etiqueta' => 'Presentación', 'tipo' => 'opciones',
                'ancho' => 'col-6 col-md-3',
                'ayuda' => '',
                // Los pares del `values` del DataWindow (`:39`): `texto<TAB>código` separados por `/`. El
                // escritorio guarda el **código**, así que la web también (la columna es `char(20)`).
                'opciones' => self::conCodigos([
                    'Cefálica' => 'C', 'Podálica' => 'P', 'Transversa' => 'T',
                    'Cefálica - Cefálica' => 'CC', 'Podálica - Podálica' => 'PP', 'Podálica - Cefálica' => 'PC',
                    'Cefálica - Transversa' => 'CT', 'Transversa - Cefálica' => 'TC', 'Transversa - Transversa' => 'TT',
                ])],
            ['columna' => 'edemas', 'etiqueta' => 'Edemas', 'tipo' => 'opciones', 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'Los edemas, por grado.',
                'opciones' => self::opciones('Grado I', 'Grado II', 'Grado III', 'Grado IV')],
            ['columna' => 'dbp', 'etiqueta' => 'DBP', 'tipo' => 'texto', 'max' => 5, 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'Diámetro biparietal.'],
            ['columna' => 'mf', 'etiqueta' => 'Placenta', 'tipo' => 'opciones', 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'La posición de la placenta.',
                'opciones' => self::opciones('Anterior', 'Posterior', 'Fúndica', 'Lateral Derecho', 'Lateral Izquierdo', 'Previa')],
            ['columna' => 'foco_f', 'etiqueta' => 'Foco Fetal', 'tipo' => 'texto', 'max' => 5, 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'La frecuencia cardíaca fetal.'],
            ['columna' => 'femur', 'etiqueta' => 'Fémur', 'tipo' => 'texto', 'max' => 5, 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'La longitud del fémur.'],
            ['columna' => 'eg_fur', 'etiqueta' => 'EG-FUR', 'tipo' => 'texto', 'max' => 20, 'ancho' => 'col-6 col-md-3',
                'ayuda' => 'La edad gestacional por la FUR, como la escribe el médico («20s + 6d»).'],
            ['columna' => 'eg_eco', 'etiqueta' => 'EG-ECO', 'tipo' => 'texto', 'max' => 20, 'ancho' => 'col-6 col-md-3',
                'ayuda' => 'La edad gestacional por la ecografía, como la escribe el médico.'],
            ['columna' => 'peso_fetal', 'etiqueta' => 'Peso F.', 'tipo' => 'texto', 'max' => 5, 'ancho' => 'col-6 col-md-2',
                'ayuda' => 'El peso fetal estimado.'],
        ];
    }

    /**
     * Las columnas que el DataWindow tiene **ocultas** (`visible="0"`, `d_pre_natal_rutin_eco_multiple.srd:44-54`):
     * la web no las toca. `eg_*` no está acá porque el escritorio sí las edita.
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_SE_TOCAN = [
        'lf', 'ac_cardiaca', 'la', 'sg_cri', 'plac_grado', 'lcc', 'dbpf', 'varice', 'multiple', 'cantidad',
    ];

    /** @return array<int,array{valor:string,texto:string}> */
    private static function opciones(string ...$textos): array
    {
        return array_map(fn (string $texto) => ['valor' => $texto, 'texto' => $texto], $textos);
    }

    /**
     * Las opciones cuyo **valor guardado** no es el texto (el caso de `presentacion`: el escritorio guarda
     * `C`, `P`, `CC`…). El texto es lo que se muestra y la clave es lo que va a la columna.
     *
     * @param  array<string,string>  $pares  texto => código
     * @return array<int,array{valor:string,texto:string}>
     */
    private static function conCodigos(array $pares): array
    {
        $opciones = [];

        foreach ($pares as $texto => $codigo) {
            $opciones[] = ['valor' => $codigo, 'texto' => $texto];
        }

        return $opciones;
    }
}
