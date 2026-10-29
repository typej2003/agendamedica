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
    ];

    /** Las opciones de los radio del escritorio (`d_pre_*.srd`, `values=`): valor guardado => texto. */
    private const ASA = ['A' => 'Clase I', 'B' => 'Clase II', 'C' => 'Clase III', 'D' => 'Clase IV', 'E' => 'Clase V'];
    private const TROMBOSIS = ['A' => 'Riesgo bajo', 'B' => 'Riesgo medio', 'C' => 'Riesgo alto'];
    private const INFECCION = ['A' => 'Limpias', 'B' => 'Limpias contaminadas', 'C' => 'Contaminadas', 'D' => 'Sucias e infectadas'];
    private const ESPIROMETRIA = ['A' => 'Riesgo bajo', 'B' => 'Riesgo medio', 'C' => 'Inoperabilidad'];
    private const ESPECIALES = ['A' => 'Nefropatías', 'B' => 'Hepatopatías', 'C' => 'Trastornos neurológicos', 'D' => 'Endocrinopatías', 'E' => 'Otros'];

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
}
