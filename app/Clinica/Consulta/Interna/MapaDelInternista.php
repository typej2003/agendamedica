<?php

namespace App\Clinica\Consulta\Interna;

use App\Models\DatosGrafica;
use App\Models\Ekg;
use App\Models\ExamenFisicoInterno;
use App\Models\PreAnestesico;
use App\Models\PreEspecial;
use App\Models\PreEspirometria;
use App\Models\PreInfeccion;
use App\Models\PreTrombo;

/**
 * **El mapa del dato de la consulta de medicina interna** (MedicReport, olas MI-2 a MI-5, 2026-10-28): qué columna guarda cada
 * campo de las solapas propias del internista, medido en `fuentes-cirugia/ventana_consulta/w_consulta_principal.srw`
 * (evento `ue_guardar`, ~línea 20200 del fuente; ver `Docs/Web/relevamiento-medicina-interna.md`).
 *
 * ⚠️ **El escritorio de medicina interna reusa columnas de `consultas` con otro significado.** Lo que en ginecología son las
 * circunferencias, acá es el laboratorio de la solapa *Plan de diagnóstico → Laboratorio*: `circcefalica` es el **HDL**,
 * `circtoraxica` los **triglicéridos**, `circabdominal` la **glicemia** y `otros` el **LDL** (`ue_guardar`: `s_cefalica =
 * …tabpage_laboratorio.em_2`, `s_toraxica = em_3`, `s_abdominal = em_4`, `otross = em_5`). La **cintura** no va a
 * `circabdominal`: va a `datos_grafica.grosor_abdominal`. Y `faringe` es la **Rx de tórax**. La web respeta ese mapa para que
 * el escritorio y la web lean lo mismo; por eso medicina interna **no** usa la evaluación física de ginecología.
 *
 * Cada campo: `clave` (el nombre en el formulario), `etiqueta`, `tabla`, `columna`, `tipo` (`numero`, `texto`, `parrafo`,
 * `si_no` u `opcion`), y según el tipo `unidad`, `max` u `opciones`. Las tablas que no son `consultas` tienen **una fila por
 * consulta** con la clave del legado de `TABLAS`.
 */
final class MapaDelInternista
{
    // Las secciones de la consulta del internista (`?seccion=`). Ver `SeccionesDeLaConsulta`.
    public const SECCION_FISICO = 'fisico-interna';
    public const SECCION_PLAN = 'plan-diagnostico';
    public const SECCION_OBSERVACIONES = 'observaciones';
    public const SECCION_ANALISIS = 'analisis-evolutivo';
    public const SECCION_PREOPERATORIA = 'preoperatoria';

    /**
     * **Resultados de exámenes** (O-49, 2026-11-02): una sección **nuestra**, no del escritorio, para el texto con
     * lo que informó el laboratorio. Guarda en la columna nueva `consultas.resultados_examenes` (nullable) y no en
     * `oido`, que en medicina interna el escritorio rotula «EXAMEN FÍSICO» (`d_preoperatoria_imprimir.srd`).
     */
    public const SECCION_RESULTADOS = 'resultados-examenes';

    /** La pestaña del riesgo quirúrgico dentro de la evaluación pre-operatoria: reusa el de cirugía pediátrica. */
    public const PESTANA_RIESGO = 'riesgo';

    /**
     * Las tablas de una fila por consulta: tabla => [modelo, columna de la historia, columna de la consulta].
     *
     * @var array<string,array{0:class-string, 1:string, 2:string}>
     */
    public const TABLAS = [
        'examen_fisico' => [ExamenFisicoInterno::class, 'nrohistoria', 'nroconsulta'],
        'datos_grafica' => [DatosGrafica::class, 'historia', 'consulta'],
        'ekg' => [Ekg::class, 'numhistoria', 'numconsulta'],
        'pre_aneste' => [PreAnestesico::class, 'historia', 'consulta'],
        'pre_trombo' => [PreTrombo::class, 'historia', 'consulta'],
        'pre_infec' => [PreInfeccion::class, 'historia', 'consulta'],
        'pre_espiro' => [PreEspirometria::class, 'historia', 'consulta'],
        'pre_espec' => [PreEspecial::class, 'historia', 'consulta'],
    ];

    /**
     * Las pestañas de cada sección: seccion => [pestaña => título]. La primera es la de entrada.
     *
     * @var array<string,array<string,string>>
     */
    public const PESTANAS = [
        self::SECCION_FISICO => [
            'signos' => 'Signos vitales',
            'funcional' => 'Examen funcional',
            'examen' => 'Examen físico',
            'resumen' => 'Resumen físico',
        ],
        self::SECCION_PLAN => [
            'ekg' => 'E.K.G.',
            'rx' => 'Rx de tórax',
            'laboratorio' => 'Laboratorio',
        ],
        self::SECCION_OBSERVACIONES => [
            'observaciones' => 'Observaciones',
        ],
        // La sección propia de la web (O-49): una sola pestaña, así que la tarjeta no dibuja la barra.
        self::SECCION_RESULTADOS => [
            'resultados' => 'Resultados de exámenes',
        ],
        self::SECCION_PREOPERATORIA => [
            'estimaciones' => 'Estimaciones',
            self::PESTANA_RIESGO => 'Riesgo operatorio',
        ],
    ];

    /** Los títulos de las secciones, como en el árbol del escritorio. */
    public const TITULOS = [
        self::SECCION_FISICO => 'Evaluación física',
        self::SECCION_PLAN => 'Plan de diagnóstico',
        self::SECCION_OBSERVACIONES => 'Observaciones',
        self::SECCION_ANALISIS => 'Análisis evolutivo',
        self::SECCION_PREOPERATORIA => 'Evaluación pre-operatoria',
        // Propia de la web (O-49): el escritorio no tiene esta sección en medicina interna.
        self::SECCION_RESULTADOS => 'Resultados de exámenes',
    ];

    /** Las opciones de los radio del escritorio (`d_pre_*.srd`, `values=`): valor guardado => texto. */
    private const ASA = ['A' => 'Clase I', 'B' => 'Clase II', 'C' => 'Clase III', 'D' => 'Clase IV', 'E' => 'Clase V'];
    private const TROMBOSIS = ['A' => 'Riesgo bajo', 'B' => 'Riesgo medio', 'C' => 'Riesgo alto'];
    private const INFECCION = ['A' => 'Limpias', 'B' => 'Limpias contaminadas', 'C' => 'Contaminadas', 'D' => 'Sucias e infectadas'];
    private const ESPIROMETRIA = ['A' => 'Riesgo bajo', 'B' => 'Riesgo medio', 'C' => 'Inoperabilidad'];
    private const ESPECIALES = ['A' => 'Nefropatías', 'B' => 'Hepatopatías', 'C' => 'Trastornos neurológicos', 'D' => 'Endocrinopatías', 'E' => 'Otros'];

    /**
     * **El texto que el escritorio escribe en «Descripción» al elegir cada opción** (O-47, parte 2a), transcrito de
     * `fuentes-cirugia/ventana_consulta/w_consulta_principal.srw` (el `doubleclicked` de cada `dw_*` de la
     * `tabpage_19`): espirometría `:25073-25086`, infección `:24952-24971`, trombosis `:25024-25037`, ASA
     * `:24848-24877` y situaciones especiales `:24895-24917`.
     *
     * La clave es la **tabla** de la escala (`pre_espiro`…), y el texto se copia **tal cual el escritorio**, con sus
     * mayúsculas, su doble espacio, su salto de línea y hasta sus erratas: lo que se imprime es lo que el médico vio
     * siempre. El marcador `{emergencia}` es el «en Emergencia,» que el escritorio interpola en la clase ASA según la
     * casilla de emergencia; lo resuelve quien lo pinta con el estado de esa casilla.
     *
     * @var array<string,array<string,string>>
     */
    private const TEXTOS_DE_LAS_ESCALAS = [
        'pre_espiro' => [
            'A' => 'RIESGO BAJO',
            'B' => "RIESGO ELEVADO\nFVC < 50% de lo anticipado   FEV1 < 40%    FEV1/FVC < 50% Ó 2LT    MBC<50% Ó 50 Lt/min   FVE 25-75: < 50%",
            'C' => "INOPERABILIDAD\nFVC 1.5Lt ó 20cc/Kg   FEV1 1Lt    FEV1/FVC: 35%  FVE 25-75: 1Lt/seg",
        ],
        'pre_trombo' => [
            'A' => "BAJO RIESGO\nEDAD < ó > 40 años   INTERVENCIÓN < 30 min Ausencia de otros factores de riesgo",
            'B' => "RIESGO MODERADO\nEDAD > 40 años   INTERVENCIÓN > 30 min Factores de riesgo secundario",
            'C' => "RIESGO ALTO\nEDAD > 40 años   INTERVENCIÓN > 30 min Ortopédica, pélvica ó abdominal po Ca.  TVP/TEP primario,  Factores de riesgo secundario Coagulopatías heredadas o adquiridas",
        ],
        'pre_infec' => [
            'A' => "HERIDAS LIMPIAS\nNo traumáticas Tec Qx sin complicación Tracto respirat, genitour y gástrico normal.",
            'B' => "HERIDAS LIMPIAS CONTAMINADAS\nApendicectomía, Ruptura vaginal, Entrada del tracto urinario o el tracto biliar en ausencia de infección, Ruptura menor en el tec Qx",
            'C' => "HERIDAS CONTAMINADAS\nMayor a compromiso de ruptura en el tec Qx, Heridas traumáticas, Cirugía del tracto biliar y genitourinario en presencia de infección.",
            'D' => "HERIDAS SUCIAS E INFECTADAS\nInflamación bac. aguda sin pus. Transección de tejido sano debido adrenaje de colección de pus, Heridas traumáticas con desvitalización del tejido, Contaminación fecal o cuerpos extraños.",
        ],
        'pre_aneste' => [
            'A' => "CLASE I\nPaciente {emergencia}normal con un proceso localizado sin afección sistémica.",
            'B' => "CLASE II\nPaciente {emergencia}con enfermedad sistémica leve.",
            'C' => "CLASE III\nPaciente {emergencia}con enfermedad sistémica grave, no incapacitante.",
            'D' => "CLASE IV\nPaciente {emergencia}con enfermedad sistémica grave, e incapacitante, lo que constituye una amenaza permanebte para su vida.",
            'E' => "CLASE V\nPaciente {emergencia}enfermo moribundo, cuya expectativa de vida no excede la 24 horas se efectue o no el tratamiento quirúrgico.",
        ],
        'pre_espec' => [
            'A' => 'NEFROPATIAS',
            'B' => 'HEPATOPATIAS',
            'C' => 'TRASTORNO NEUROLÓGICO',
            'D' => 'ENDOCRINOPATIAS',
            'E' => 'OTROS',
        ],
    ];

    /**
     * Los campos de cada pestaña: pestaña => lista de campos, en el orden de la pantalla del escritorio.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function campos(): array
    {
        $examen = fn (string $columna, string $etiqueta) => ['clave' => $columna, 'etiqueta' => $etiqueta, 'tabla' => 'examen_fisico', 'columna' => $columna, 'tipo' => 'parrafo'];
        $lab = fn (string $clave, string $etiqueta, string $tabla, string $columna, ?string $unidad = null) => ['clave' => $clave, 'etiqueta' => $etiqueta, 'tabla' => $tabla, 'columna' => $columna, 'tipo' => 'numero', 'unidad' => $unidad];
        $ekgSiNo = fn (string $columna, string $etiqueta) => ['clave' => 'ekg_' . $columna, 'etiqueta' => $etiqueta, 'tabla' => 'ekg', 'columna' => $columna, 'tipo' => 'si_no'];
        $ekgTexto = fn (string $columna, string $etiqueta) => ['clave' => 'ekg_' . $columna, 'etiqueta' => $etiqueta, 'tabla' => 'ekg', 'columna' => $columna, 'tipo' => 'texto', 'max' => 60];
        $escala = fn (string $tabla, string $etiqueta, array $opciones, int $max = 0) => [
            ['clave' => $tabla . '_tipo', 'etiqueta' => $etiqueta, 'tabla' => $tabla, 'columna' => 'tipo', 'tipo' => 'opcion', 'opciones' => $opciones],
            ['clave' => $tabla . '_resultado', 'etiqueta' => 'Descripción', 'tabla' => $tabla, 'columna' => 'resultado', 'tipo' => 'parrafo', 'max' => $max ?: null],
        ];

        return [
            // Evaluación física → Signos vitales (`tabpage_4`). La circunferencia torácica y la abdominal, las temperaturas y la
            // bioimpedancia están ocultas en el escritorio salvo las temperaturas, que sí se guardan.
            'signos' => [
                ['clave' => 'peso', 'etiqueta' => 'Peso', 'tabla' => 'consultas', 'columna' => 'peso', 'tipo' => 'numero', 'unidad' => 'kg'],
                ['clave' => 'talla', 'etiqueta' => 'Talla', 'tabla' => 'consultas', 'columna' => 'talla', 'tipo' => 'numero', 'unidad' => 'm'],
                ['clave' => 'cintura', 'etiqueta' => 'Cintura', 'tabla' => 'datos_grafica', 'columna' => 'grosor_abdominal', 'tipo' => 'numero', 'unidad' => 'cm'],
                ['clave' => 'pp', 'etiqueta' => 'Frecuencia respiratoria', 'tabla' => 'consultas', 'columna' => 'pp', 'tipo' => 'numero', 'unidad' => 'x min'],
                ['clave' => 'fc', 'etiqueta' => 'Frecuencia cardiaca', 'tabla' => 'consultas', 'columna' => 'fc', 'tipo' => 'numero', 'unidad' => 'x min'],
                ['clave' => 'tasentado', 'etiqueta' => 'Tensión sentado', 'tabla' => 'consultas', 'columna' => 'tasentado', 'tipo' => 'texto', 'max' => 10, 'unidad' => 'mm/Hg'],
                ['clave' => 'taacostado', 'etiqueta' => 'Tensión acostado', 'tabla' => 'consultas', 'columna' => 'taacostado', 'tipo' => 'texto', 'max' => 10, 'unidad' => 'mm/Hg'],
                ['clave' => 'tapie', 'etiqueta' => 'Tensión de pie', 'tabla' => 'consultas', 'columna' => 'tapie', 'tipo' => 'texto', 'max' => 10, 'unidad' => 'mm/Hg'],
                ['clave' => 'tem_oral', 'etiqueta' => 'Temperatura oral', 'tabla' => 'consultas', 'columna' => 'tem_oral', 'tipo' => 'numero', 'unidad' => 'ºC'],
                ['clave' => 'tem_axilar', 'etiqueta' => 'Temperatura axilar', 'tabla' => 'consultas', 'columna' => 'tem_axilar', 'tipo' => 'numero', 'unidad' => 'ºC'],
                ['clave' => 'tem_otica', 'etiqueta' => 'Temperatura ótica', 'tabla' => 'consultas', 'columna' => 'tem_otica', 'tipo' => 'numero', 'unidad' => 'ºC'],
            ],
            // Evaluación física → Examen funcional (`tabpage_47`, `mle_7` → `consultas.funcionae`).
            'funcional' => [
                ['clave' => 'funcionae', 'etiqueta' => 'Examen funcional', 'tabla' => 'consultas', 'columna' => 'funcionae', 'tipo' => 'parrafo'],
            ],
            // Evaluación física → Examen físico (`tabpage_5`), en las tres columnas del escritorio. «Cabeza» es `mle_gene` y
            // «Tórax» es `mle_miz`: los nombres de los controles no son los de las columnas.
            'examen' => [
                $examen('genera', 'General'),
                $examen('ojo_de', 'Ojos'),
                $examen('boca_de', 'Boca'),
                $examen('cabeza', 'Cabeza'),
                $examen('orl', 'ORL'),
                $examen('cuello_tiroides', 'Cuello y tiroides'),
                $examen('cardiopulmonal', 'Cardiopulmonar'),
                $examen('torax', 'Tórax'),
                $examen('abdomen', 'Abdomen'),
                $examen('extremidades', 'Extremidades'),
                $examen('neurologico', 'Neurológico'),
                $examen('vascular', 'Vascular'),
                $examen('piel_mucosas', 'Piel'),
                $examen('mucosas', 'Mucosas'),
                $examen('otros', 'Otros'),
            ],
            // Evaluación física → Resumen físico (`tabpage_18`, `mle_resultados` → `consultas.resultadoexamencomp`).
            'resumen' => [
                ['clave' => 'resultadoexamencomp', 'etiqueta' => 'Resumen físico', 'tabla' => 'consultas', 'columna' => 'resultadoexamencomp', 'tipo' => 'parrafo'],
            ],
            // Plan de diagnóstico → E.K.G. (`tabpage_ekg`, DataWindow `d_dd_akg`). El rótulo «QRS» del DataWindow es la columna
            // `alteraciones`; los bloqueos son casillas `S`/`N`.
            'ekg' => [
                $ekgTexto('ritmo', 'Ritmo'),
                $ekgTexto('fc', 'FC'),
                $ekgTexto('pr', 'PR'),
                $ekgTexto('alteraciones', 'QRS'),
                $ekgTexto('aqrs', 'AQRS'),
                $ekgTexto('qt', 'QT'),
                $ekgTexto('ap', 'AP'),
                $ekgTexto('at', 'AT'),
                $ekgSiNo('hbsai', 'HBSAI'),
                $ekgSiNo('birdhh', 'BIRDHH'),
                $ekgSiNo('bcrdhh', 'BCRDHH'),
                $ekgSiNo('birihh', 'BIRIHH'),
                $ekgSiNo('bcrihh', 'BCRIHH'),
                $ekgSiNo('bav', 'BAV'),
                $ekgSiNo('primer', 'I grado'),
                $ekgSiNo('segundo', 'II grado'),
                $ekgSiNo('tercero', 'III grado'),
                ['clave' => 'ekg_hallazgos', 'etiqueta' => 'Hallazgos', 'tabla' => 'ekg', 'columna' => 'hallazgos', 'tipo' => 'parrafo'],
                ['clave' => 'ekg_conclusiones', 'etiqueta' => 'Conclusiones', 'tabla' => 'ekg', 'columna' => 'conclusiones', 'tipo' => 'parrafo'],
            ],
            // Plan de diagnóstico → R.X. T. (`mle_rayosx` → `consultas.faringe`).
            'rx' => [
                ['clave' => 'faringe', 'etiqueta' => 'Rx de tórax', 'tabla' => 'consultas', 'columna' => 'faringe', 'tipo' => 'parrafo'],
            ],
            // Plan de diagnóstico → Laboratorio, el cuadro «Resultados incidentes» (`gb_7`), en sus tres columnas.
            'laboratorio' => [
                $lab('colesterol', 'Colesterol', 'datos_grafica', 'otro_13', 'mg/dl'),
                $lab('hdl', 'HDL', 'consultas', 'circcefalica', 'mg/dl'),
                $lab('ldl', 'LDL', 'consultas', 'otros', 'mg/dl'),
                $lab('trigliceridos', 'Triglicéridos', 'consultas', 'circtoraxica', 'mg/dl'),
                $lab('glicemia', 'Glicemia', 'consultas', 'circabdominal', 'mg/dl'),
                $lab('microalbuminuria', 'Microalbuminuria', 'datos_grafica', 'micro_album'),
                $lab('hb_glicosilada', 'Hemoglobina glicosilada', 'datos_grafica', 'otro_4', '%'),
                $lab('peptido_c', 'Péptido C', 'datos_grafica', 'otro_5'),
                $lab('urea', 'Urea', 'datos_grafica', 'otro_6', 'mg/dl'),
                $lab('depuracion', 'Depuración de creatinina', 'datos_grafica', 'otro_7', 'ml/min'),
                $lab('creatinina', 'Creatinina', 'datos_grafica', 'otro_8', 'mg/dl'),
                $lab('proteinuria', 'Proteinuria', 'datos_grafica', 'otro_9'),
                $lab('homocisteina', 'Homocisteína', 'datos_grafica', 'otro_10'),
                $lab('anti_insulina', 'Anticuerpos anti-insulina', 'datos_grafica', 'otro_11'),
                $lab('fibrinogeno', 'Fibrinógeno', 'datos_grafica', 'otro_12', 'mg/dl'),
            ],
            // Observaciones (`tabpage_21`, `mle_observaciones` → `consultas.observaciones`).
            'observaciones' => [
                ['clave' => 'observaciones', 'etiqueta' => 'Observaciones', 'tabla' => 'consultas', 'columna' => 'observaciones', 'tipo' => 'parrafo'],
            ],
            // Resultados de exámenes (O-49): la columna **nueva** `consultas.resultados_examenes` (nullable). No es del
            // escritorio y no reusa `oido` —allí «EXAMEN FÍSICO»— ni `resultadoexamencomp` —el resumen físico—.
            'resultados' => [
                ['clave' => 'resultados_examenes', 'etiqueta' => 'Resultados de exámenes', 'tabla' => 'consultas', 'columna' => 'resultados_examenes', 'tipo' => 'parrafo'],
            ],
            // Evaluación pre-operatoria → Estimaciones (`tabpage_37`): las cinco escalas, una fila por consulta en cada tabla.
            'estimaciones' => array_merge(
                $escala('pre_aneste', 'Estado físico (ASA)', self::ASA, 200),
                [['clave' => 'pre_aneste_emergencia', 'etiqueta' => 'Emergencia', 'tabla' => 'pre_aneste', 'columna' => 'emergencia', 'tipo' => 'si_no']],
                $escala('pre_trombo', 'Riesgo de trombosis venosa y tromboembolismo', self::TROMBOSIS),
                $escala('pre_infec', 'Infección de heridas operatorias', self::INFECCION),
                $escala('pre_espiro', 'Espirometría', self::ESPIROMETRIA, 200),
                $escala('pre_espec', 'Situaciones especiales', self::ESPECIALES),
            ),
        ];
    }

    /** Los campos de una pestaña (lista vacía si no existe). */
    public static function deLaPestana(string $pestana): array
    {
        return self::campos()[$pestana] ?? [];
    }

    /** La pestaña de entrada de una sección, o la pedida si existe en ella. */
    public static function pestana(string $seccion, string $pedida): string
    {
        $pestanas = self::PESTANAS[$seccion] ?? [];

        return array_key_exists($pedida, $pestanas) ? $pedida : (string) array_key_first($pestanas);
    }

    /** ¿La clave es una sección propia del internista? */
    public static function esSeccion(?string $seccion): bool
    {
        return array_key_exists((string) $seccion, self::TITULOS);
    }

    /** El texto de una opción de escala (`A` → «Clase I»), o el valor crudo si no es una opción conocida. */
    public static function textoDeOpcion(string $clave, ?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        foreach (self::campos() as $campos) {
            foreach ($campos as $campo) {
                if ($campo['clave'] === $clave && isset($campo['opciones'])) {
                    return $campo['opciones'][trim($valor)] ?? trim($valor);
                }
            }
        }

        return trim($valor);
    }

    /**
     * **El texto de cada opción de una escala** (O-47): valor guardado => lo que el escritorio escribe en
     * «Descripción». Acepta la clave del campo (`pre_espiro_tipo`) o la de la tabla (`pre_espiro`).
     *
     * Lo usan la pantalla —para cargar el texto al elegir la opción— y la hoja impresa, que así no lo repite.
     *
     * @return array<string,string>
     */
    public static function textosDeLaEscala(string $clave): array
    {
        return self::TEXTOS_DE_LAS_ESCALAS[self::escalaDeLaClave($clave)] ?? [];
    }

    /** El texto completo de una opción de escala, o `null` si el valor está vacío o no es de esa escala. */
    public static function textoCompletoDeLaOpcion(string $clave, ?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        return self::textosDeLaEscala($clave)[$valor] ?? null;
    }

    /** La tabla de una escala a partir de la clave de su campo (`pre_espiro_tipo` → `pre_espiro`). */
    private static function escalaDeLaClave(string $clave): string
    {
        return str_ends_with($clave, '_tipo') ? substr($clave, 0, -strlen('_tipo')) : $clave;
    }
}
