<?php

namespace App\Clinica\Consulta\Cardiologia;

use App\Clinica\Consulta\Interna\MapaDelInternista;
use App\Models\DopplerArterialMiembrosInferiores;
use App\Models\DopplerCarotidas;
use App\Models\DopplerMiembrosSuperiores;
use App\Models\DopplerVenoso;
use App\Models\Ecocardiografia;
use App\Models\EcocardiogramaEte;
use App\Models\EcocardiograficoInforme;
use App\Models\Ekg;
use App\Models\ImplantacionMarcapasos;
use App\Models\Oscilometria;
use App\Models\PruebaDeEsfuerzo;

/**
 * **El mapa del dato de los estudios cardiológicos** (CAR-2, 2026-11-04): qué columna guarda cada campo, medido en
 * `fuentes-cardiologia/ventana_consulta/w_consulta_principal.srw` y en el esquema real
 * (`esquemas-asa/cardiologia.sql`; ver `Docs/Web/relevamiento-cardiologia.md`).
 *
 * Los estudios del cardiólogo viven en tablas **de una fila por consulta** con la clave del legado
 * (`TABLAS`). El escritorio los abre desde «Plan de diagnóstico» y cada uno tiene su ventana:
 *  - `ecocardiografia` → `w_cargar_visualizar_ecocardiografia` (medidas y conclusiones del eco transtorácico);
 *  - `ecocardiograma_ete` → `w_cargar_visualizar_ete` (transesofágico);
 *  - `prueba_esfuerzo`, `oscilometria`, `dd_arterial_venoso_ms`, `dd_arterias_carotidas_dos`, `ddflujo_venoso`,
 *    `implantacion_marcapasos`, `implante` (CAR-3 a CAR-5).
 *
 * CAR-2 entra con el **ecocardiograma transtorácico**; CAR-3 suma el **informe del eco de estrés** y el **ETE**; las demás
 * pestañas se agregan con su ola, sin tocar esta.
 *
 * Cada campo: `clave` (el nombre en el formulario), `etiqueta`, `tabla`, `columna`, `tipo` (`texto`, `parrafo`, `si_no` u
 * `opcion`), `max` cuando la columna del legado tiene largo y `opciones` en las de tipo `opcion`.
 */
final class MapaDelCardiologo
{
    /** La sección de los estudios cardiológicos dentro de la consulta (`?seccion=cardiologia`). */
    public const SECCION = 'cardiologia';

    /** Las pestañas de la sección: eco transtorácico (CAR-2), estrés y ETE (CAR-3) y los Doppler (CAR-4). */
    public const PESTANA_ECO = 'eco';
    public const PESTANA_ESTRES = 'estres';
    public const PESTANA_ETE = 'ete';
    public const PESTANA_DOPPLER_MS = 'doppler-ms';
    public const PESTANA_CAROTIDAS = 'carotidas';
    public const PESTANA_VENOSO = 'doppler-venoso';
    public const PESTANA_ESFUERZO = 'esfuerzo';
    public const PESTANA_OSCILOMETRIA = 'oscilometria';
    public const PESTANA_MARCAPASOS = 'marcapasos';
    public const PESTANA_ARTERIAL_MI = 'doppler-arterial-mi';
    /** El **E.K.G.** (CAR-5): reusa el mapa y la tabla de medicina interna (MI-3). */
    public const PESTANA_EKG = 'ekg';

    /**
     * Las tablas de una fila por consulta: tabla => [modelo, columna de la historia, columna de la consulta].
     *
     * @var array<string,array{0:class-string, 1:string, 2:string}>
     */
    public const TABLAS = [
        'ecocardiografia' => [Ecocardiografia::class, 'numhistoria', 'numconsulta'],
        'ecocardiografico_in' => [EcocardiograficoInforme::class, 'numhistoria', 'numconsulta'],
        'ecocardiograma_ete' => [EcocardiogramaEte::class, 'numhistoria', 'numconsulta'],
        'dd_arterial_venoso_ms' => [DopplerMiembrosSuperiores::class, 'historia', 'consulta'],
        'dd_arterias_carotidas_dos' => [DopplerCarotidas::class, 'numhistoria', 'numconsulta'],
        'ddflujo_venoso' => [DopplerVenoso::class, 'numhistoria', 'numconsulta'],
        'prueba_esfuerzo' => [PruebaDeEsfuerzo::class, 'numhistoria', 'numconsulta'],
        'oscilometria' => [Oscilometria::class, 'historia', 'consulta'],
        'implantacion_marcapasos' => [ImplantacionMarcapasos::class, 'historia', 'consulta'],
        // ⚠️ Clave del legado `numhistoria` + `num_consulta` (con guion bajo), no `numconsulta`.
        'dd_arterial_mi' => [DopplerArterialMiembrosInferiores::class, 'numhistoria', 'num_consulta'],
        // El E.K.G. es la misma tabla de medicina interna (MI-3): clave `numhistoria` + `numconsulta`.
        'ekg' => [Ekg::class, 'numhistoria', 'numconsulta'],
    ];

    /**
     * Las pestañas por sección: seccion => [pestaña => título]. La primera es la de entrada.
     *
     * @var array<string,array<string,string>>
     */
    public const PESTANAS = [
        self::SECCION => [
            self::PESTANA_ECO => 'Ecocardiograma',
            self::PESTANA_ESTRES => 'Eco de estrés',
            self::PESTANA_ETE => 'Eco transesofágico',
            self::PESTANA_DOPPLER_MS => 'Doppler de miembros superiores',
            self::PESTANA_CAROTIDAS => 'Doppler carotídeo',
            self::PESTANA_VENOSO => 'Doppler venoso de miembros inferiores',
            self::PESTANA_ESFUERZO => 'Prueba de esfuerzo',
            self::PESTANA_OSCILOMETRIA => 'Oscilometría',
            self::PESTANA_MARCAPASOS => 'Marcapasos',
            self::PESTANA_ARTERIAL_MI => 'Doppler arterial de miembros inferiores',
            self::PESTANA_EKG => 'E.K.G.',
        ],
    ];

    /** El título de la sección, como el ítem del escritorio. */
    public const TITULOS = [
        self::SECCION => 'Estudios cardiológicos',
    ];

    /**
     * Los campos de cada pestaña, en el orden del escritorio.
     *
     * El eco transtorácico mide en centímetros (el escritorio guarda texto de 15 en cada columna) y cierra con las
     * conclusiones, que es lo que se imprime.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function campos(): array
    {
        $medida = fn (string $clave, string $etiqueta, string $columna, ?string $unidad = 'cm') => [
            'clave' => $clave, 'etiqueta' => $etiqueta, 'tabla' => 'ecocardiografia', 'columna' => $columna,
            'tipo' => 'texto', 'max' => 15, 'unidad' => $unidad,
        ];

        return [
            self::PESTANA_ECO => [
                $medida('ha_ai', 'Aurícula izquierda', 'ha_ai'),
                $medida('ha_rao', 'Raíz aórtica', 'ha_rao'),
                $medida('ha_gpavd', 'Grosor de la pared anterior del VD', 'ha_gpavd'),
                $medida('ha_cavidad_vd', 'Cavidad del VD', 'ha_cavidad_vd'),
                $medida('ha_septum_iv', 'Septum interventricular', 'ha_septum_iv'),
                $medida('ha_pared_post', 'Pared posterior', 'ha_pared_post'),
                $medida('ha_ddvi', 'Diámetro diastólico del VI (DDVI)', 'ha_ddvi'),
                $medida('ha_dsvi', 'Diámetro sistólico del VI (DSVI)', 'ha_dsvi'),
                ['clave' => 'ha_ivsvi', 'etiqueta' => 'Índice de volumen sistólico del VI (IVSVI)', 'tabla' => 'ecocardiografia',
                    'columna' => 'ha_ivsvi', 'tipo' => 'texto', 'max' => 200],
                $medida('ha_fa', 'Fracción de acortamiento (FA)', 'ha_fa', '%'),
                $medida('ha_fe', 'Fracción de eyección (FE)', 'ha_fe', '%'),
                ['clave' => 'conclusiones', 'etiqueta' => 'Conclusiones', 'tabla' => 'ecocardiografia',
                    'columna' => 'conclusiones', 'tipo' => 'parrafo'],
            ],
            // CAR-3: el informe del **eco de estrés** (`d_ecocardiografico_inf_fuerte.srd`). `super_texto` y `tipo` no se
            // editan: el primero es el texto compuesto que arma el escritorio y el segundo un código interno.
            self::PESTANA_ESTRES => [
                ['clave' => 'diag_estres', 'etiqueta' => 'Diagnóstico', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'diagnostico', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'motivo_estudio', 'etiqueta' => 'Motivo de estudio', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'motivo_estudio', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'tratamiento', 'etiqueta' => 'Tratamiento', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'tratamiento', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'fc_maxima', 'etiqueta' => 'FC máxima', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'fc_maxima', 'tipo' => 'texto', 'max' => 10, 'unidad' => 'lpm'],
                ['clave' => 'fc_submaxima', 'etiqueta' => 'FC submáxima', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'fc_submaxima', 'tipo' => 'texto', 'max' => 10, 'unidad' => 'lpm'],
                ['clave' => 'farmacologico', 'etiqueta' => 'Farmacológico', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'farmacologico', 'tipo' => 'si_no'],
                ['clave' => 'medicamento', 'etiqueta' => 'Medicamento', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'medicamento', 'tipo' => 'opcion', 'opciones' => [
                        'Persantin' => 'Persantin',
                        'Persantin/Dubutamina' => 'Persantin / Dubutamina',
                        'Dubutamina' => 'Dubutamina',
                    ]],
                ['clave' => 'hallasgos', 'etiqueta' => 'Hallazgos', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'hallasgos', 'tipo' => 'parrafo'],
                ['clave' => 'conclusion_estres', 'etiqueta' => 'Conclusión', 'tabla' => 'ecocardiografico_in',
                    'columna' => 'conclusion', 'tipo' => 'parrafo'],
            ],
            // CAR-3: el **eco transesofágico** (`d_ecocardiograma_ete.srd`). `medico` es la cédula del médico que lo hace y
            // no se edita desde acá: la web ya sabe quién atiende la consulta.
            self::PESTANA_ETE => [
                ['clave' => 'diag_ete', 'etiqueta' => 'Diagnóstico', 'tabla' => 'ecocardiograma_ete',
                    'columna' => 'diagnostico', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'indicacion', 'etiqueta' => 'Indicación', 'tabla' => 'ecocardiograma_ete',
                    'columna' => 'indicacion', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'sedacion', 'etiqueta' => 'Sedación', 'tabla' => 'ecocardiograma_ete',
                    'columna' => 'sedacion', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'video_num', 'etiqueta' => 'Video N.º', 'tabla' => 'ecocardiograma_ete',
                    'columna' => 'video_num', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'transductor_ete', 'etiqueta' => 'Transductor', 'tabla' => 'ecocardiograma_ete',
                    'columna' => 'transductor', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'equipo_ete', 'etiqueta' => 'Equipo', 'tabla' => 'ecocardiograma_ete',
                    'columna' => 'equipo', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'conclusiones_ete', 'etiqueta' => 'Conclusión', 'tabla' => 'ecocardiograma_ete',
                    'columna' => 'conclusiones', 'tipo' => 'parrafo'],
            ],
            // CAR-4: el **Doppler arterial y venoso de los miembros superiores** (`dd_arterial_venoso_ms`). El escritorio lo
            // comparte con la oscilometría en la ventana de miembros superiores; la web los separa.
            self::PESTANA_DOPPLER_MS => [
                ['clave' => 'indicacion_ms', 'etiqueta' => 'Indicación', 'tabla' => 'dd_arterial_venoso_ms',
                    'columna' => 'indicacion', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'equipo_ms', 'etiqueta' => 'Equipo', 'tabla' => 'dd_arterial_venoso_ms',
                    'columna' => 'equipo', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'transductor_ms', 'etiqueta' => 'Transductor', 'tabla' => 'dd_arterial_venoso_ms',
                    'columna' => 'transductor', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'miembro_superior_derecho', 'etiqueta' => 'Miembro superior derecho', 'tabla' => 'dd_arterial_venoso_ms',
                    'columna' => 'miembro_superior_derecho', 'tipo' => 'parrafo'],
                ['clave' => 'miembro_superior_izquierdo', 'etiqueta' => 'Miembro superior izquierdo', 'tabla' => 'dd_arterial_venoso_ms',
                    'columna' => 'miembro_superior_izquierdo', 'tipo' => 'parrafo'],
                ['clave' => 'texto_ms', 'etiqueta' => 'Texto del informe', 'tabla' => 'dd_arterial_venoso_ms',
                    'columna' => 'texto', 'tipo' => 'parrafo'],
                ['clave' => 'conclusion_ms', 'etiqueta' => 'Conclusión', 'tabla' => 'dd_arterial_venoso_ms',
                    'columna' => 'conclusion', 'tipo' => 'parrafo'],
            ],
            // CAR-4: el **Doppler carotídeo** (`dd_arterias_carotidas_dos`). Las medidas son velocidades sistólicas pico en
            // cm/s, como en `d_dd_carotidas.srd`; `ar_aci_ace_izq` es la razón ACI/ACE izquierda (el nombre del esquema).
            self::PESTANA_CAROTIDAS => array_merge(
                [
                    ['clave' => 'indicacion_car', 'etiqueta' => 'Indicación', 'tabla' => 'dd_arterias_carotidas_dos',
                        'columna' => 'indicacion', 'tipo' => 'texto', 'max' => 100],
                    ['clave' => 'equipo_car', 'etiqueta' => 'Equipo', 'tabla' => 'dd_arterias_carotidas_dos',
                        'columna' => 'equipo', 'tipo' => 'texto', 'max' => 60],
                    ['clave' => 'transductor_car', 'etiqueta' => 'Transductor', 'tabla' => 'dd_arterias_carotidas_dos',
                        'columna' => 'transductor', 'tipo' => 'texto', 'max' => 60],
                ],
                self::medidasCarotidas(),
                [
                    ['clave' => 'hallasgos_car', 'etiqueta' => 'Hallazgos', 'tabla' => 'dd_arterias_carotidas_dos',
                        'columna' => 'hallasgos', 'tipo' => 'parrafo'],
                    ['clave' => 'conclusion_car', 'etiqueta' => 'Conclusión', 'tabla' => 'dd_arterias_carotidas_dos',
                        'columna' => 'conclusion', 'tipo' => 'texto', 'max' => 250],
                ],
            ),
            // CAR-4: el **Doppler venoso de miembros inferiores** (`ddflujo_venoso`). El escritorio lo muestra como una
            // grilla de venas × fases (espontáneo, fásico, augmentado y reflujo) por lado; acá se agrupa por vena.
            self::PESTANA_VENOSO => array_merge(
                [
                    ['clave' => 'indicacion_venoso', 'etiqueta' => 'Indicación', 'tabla' => 'ddflujo_venoso',
                        'columna' => 'indicacion', 'tipo' => 'texto', 'max' => 100],
                    ['clave' => 'equipo_venoso', 'etiqueta' => 'Equipo', 'tabla' => 'ddflujo_venoso',
                        'columna' => 'equipo', 'tipo' => 'texto', 'max' => 60],
                    ['clave' => 'transductor_venoso', 'etiqueta' => 'Transductor', 'tabla' => 'ddflujo_venoso',
                        'columna' => 'tansductor', 'tipo' => 'texto', 'max' => 60],
                ],
                self::rejillaVenosa(),
                [
                    ['clave' => 'texto_venoso', 'etiqueta' => 'Texto del informe', 'tabla' => 'ddflujo_venoso',
                        'columna' => 'texto_reporte', 'tipo' => 'parrafo'],
                    ['clave' => 'conclusion_venoso', 'etiqueta' => 'Conclusiones', 'tabla' => 'ddflujo_venoso',
                        'columna' => 'conclusiones', 'tipo' => 'parrafo'],
                ],
            ),
            // CAR-5: la **prueba de esfuerzo** (`d_d_prueba_esfuerzo.srd`).
            self::PESTANA_ESFUERZO => [
                ['clave' => 'diag_esfuerzo', 'etiqueta' => 'Diagnóstico', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'diagnostico', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'tratamiento_anterior', 'etiqueta' => 'Tratamiento antes de la prueba', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'tratamiento_anterior', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'fre_max_alc', 'etiqueta' => 'Frecuencia máxima', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'fre_max_alc', 'tipo' => 'texto', 'max' => 30, 'unidad' => 'lpm'],
                ['clave' => 'fre_sub_max', 'etiqueta' => 'Frecuencia submáxima', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'fre_sub_max', 'tipo' => 'texto', 'max' => 30, 'unidad' => 'lpm'],
                ['clave' => 'frec_alc_xmin', 'etiqueta' => 'Frecuencia alcanzada (1 × min)', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'frec_alc_xmin', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'porc_fre_max', 'etiqueta' => '% de frecuencia máxima', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'porc_fre_max', 'tipo' => 'texto', 'max' => 30, 'unidad' => '%'],
                ['clave' => 'fcm', 'etiqueta' => 'FCM', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'fcm', 'tipo' => 'texto', 'max' => 60],
                ['clave' => 'mets', 'etiqueta' => 'Mets', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'mets', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'cons_max_oxigeno', 'etiqueta' => 'Consumo máximo de oxígeno', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'cons_max_oxigeno', 'tipo' => 'texto', 'max' => 30, 'unidad' => 'ccO2/kg/min'],
                ['clave' => 'clasif_nyha', 'etiqueta' => 'Clasificación funcional (NYHA)', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'clasif_nyha', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'clasif_astrand', 'etiqueta' => 'Clasificación Astrand', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'clasif_astrand', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'nivel_entrenamiento', 'etiqueta' => 'Nivel de entrenamiento', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'nivel_entrenamiento', 'tipo' => 'texto', 'max' => 30],
                ['clave' => 'observaciones_esfuerzo', 'etiqueta' => 'Observaciones', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'observaciones', 'tipo' => 'parrafo'],
                ['clave' => 'conclusion_esfuerzo', 'etiqueta' => 'Conclusiones', 'tabla' => 'prueba_esfuerzo',
                    'columna' => 'conclusiones', 'tipo' => 'parrafo'],
            ],
            // CAR-5: la **oscilometría** (`d_dd_osciloscopio.srd`): índices, gradientes segmentarios y compensación.
            self::PESTANA_OSCILOMETRIA => array_merge(
                [
                    ['clave' => 'indicacion_oscilo', 'etiqueta' => 'Indicación', 'tabla' => 'oscilometria',
                        'columna' => 'indicacion', 'tipo' => 'texto', 'max' => 30],
                ],
                self::indicesOscilometria(),
                [
                    ['clave' => 'tobillos', 'etiqueta' => 'Tobillos', 'tabla' => 'oscilometria',
                        'columna' => 'tobillos', 'tipo' => 'texto', 'max' => 30],
                    ['clave' => 'pantorrillas', 'etiqueta' => 'Pantorrillas', 'tabla' => 'oscilometria',
                        'columna' => 'pantorrillas', 'tipo' => 'texto', 'max' => 30],
                    ['clave' => 'muslo_distal', 'etiqueta' => 'Muslo (distal)', 'tabla' => 'oscilometria',
                        'columna' => 'muslo_distal', 'tipo' => 'texto', 'max' => 30],
                    ['clave' => 'muslo_proximal', 'etiqueta' => 'Muslo (proximal)', 'tabla' => 'oscilometria',
                        'columna' => 'muslo_proximal', 'tipo' => 'texto', 'max' => 30],
                    ['clave' => 'conclusion_oscilo', 'etiqueta' => 'Conclusiones', 'tabla' => 'oscilometria',
                        'columna' => 'conclusiones', 'tipo' => 'parrafo'],
                ],
            ),
            // CAR-5: el **marcapasos** (`d_marcapasos.srd`): el generador, su programación, las mediciones y el implante.
            self::PESTANA_MARCAPASOS => self::marcapasos(),
            // CAR-5: el **Doppler arterial de miembros inferiores** (`d_dd_arterial_miembros_inf.srd`).
            self::PESTANA_ARTERIAL_MI => array_merge(
                [
                    ['clave' => 'indicacion_arterial_mi', 'etiqueta' => 'Indicación', 'tabla' => 'dd_arterial_mi',
                        'columna' => 'indicacion', 'tipo' => 'texto', 'max' => 100],
                    ['clave' => 'equipo_arterial_mi', 'etiqueta' => 'Equipo', 'tabla' => 'dd_arterial_mi',
                        'columna' => 'equipo', 'tipo' => 'texto', 'max' => 30],
                    ['clave' => 'transductor_arterial_mi', 'etiqueta' => 'Transductor', 'tabla' => 'dd_arterial_mi',
                        'columna' => 'tansductor', 'tipo' => 'texto', 'max' => 30],
                ],
                self::arteriasMiembrosInferiores(),
                [
                    ['clave' => 'conclusion_arterial_mi', 'etiqueta' => 'Conclusión', 'tabla' => 'dd_arterial_mi',
                        'columna' => 'conclusion', 'tipo' => 'parrafo'],
                ],
            ),
            // CAR-5: el **E.K.G.** (reusa el mapa y la tabla de medicina interna, MI-3).
            self::PESTANA_EKG => self::camposDelEkg(),
        ];
    }

    /**
     * Los campos del E.K.G., tomados del mapa de medicina interna con las claves prefijadas (`cardio_`) para no chocar con
     * las de los estudios del cardiólogo. Las columnas y la tabla (`ekg`) son las mismas: el escritorio comparte el dato.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function camposDelEkg(): array
    {
        // Sólo las columnas de `ekg` (el mapa del internista comparte la tabla con la web).
        $campos = array_filter(
            MapaDelInternista::deLaPestana('ekg'),
            fn (array $campo) => $campo['tabla'] === 'ekg',
        );

        return array_map(
            fn (array $campo) => array_merge($campo, ['clave' => 'cardio_' . $campo['clave']]),
            array_values($campos),
        );
    }

    /**
     * Las arterias del Doppler de miembros inferiores: la velocidad sistólica pico de cada lado (`_der`/`_izq`, `float`) y la
     * morfología de la arteria (`char(5)`), que es una sola para las dos piernas, como en `d_dd_arterial_miembros_inf.srd`.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function arteriasMiembrosInferiores(): array
    {
        $arterias = [
            'iliaca_ext' => 'Ilíaca externa',
            'feromonal_comun' => 'Femoral común',
            'fer_superf_prox' => 'Femoral superficial (proximal)',
            'fer_superf_distal' => 'Femoral superficial (distal)',
            'poplitea' => 'Poplítea',
            'tibial_ante' => 'Tibial anterior',
            'tibial_post' => 'Tibial posterior',
            'peronea' => 'Peronea',
        ];

        $campos = [];

        foreach ($arterias as $columna => $titulo) {
            foreach (['der' => 'derecha', 'izq' => 'izquierda'] as $lado => $texto) {
                $campos[] = [
                    'clave' => $columna . '_' . $lado, 'etiqueta' => 'Velocidad sistólica pico (' . $texto . ')',
                    'grupo' => $titulo, 'tabla' => 'dd_arterial_mi', 'columna' => $columna . '_' . $lado,
                    'tipo' => 'numero', 'unidad' => 'cm/s',
                ];
            }

            $campos[] = [
                'clave' => $columna . '_morfologia', 'etiqueta' => 'Morfología', 'grupo' => $titulo,
                'tabla' => 'dd_arterial_mi', 'columna' => $columna, 'tipo' => 'texto', 'max' => 5,
            ];
        }

        return $campos;
    }

    /**
     * Los índices y gradientes de la oscilometría, en pares derecha / izquierda como el DataWindow.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function indicesOscilometria(): array
    {
        $par = function (string $colDer, string $colIzq, string $claveBase, string $etiqueta, ?string $unidad = null) {
            $campos = [];

            foreach (['der' => ['derecho', $colDer], 'izq' => ['izquierdo', $colIzq]] as $lado => [$texto, $columna]) {
                $campos[] = [
                    'clave' => $claveBase . '_' . $lado, 'etiqueta' => $etiqueta . ' (' . $texto . ')',
                    'grupo' => $etiqueta, 'tabla' => 'oscilometria', 'columna' => $columna,
                    'tipo' => 'numero', 'unidad' => $unidad,
                ];
            }

            return $campos;
        };

        return array_merge(
            $par('ind_tobillo_brazo_derecho', 'ind_tobillo_brazo_izquierdo', 'itb_tobillo', 'Índice tobillo-brazo (ITB)'),
            $par('ind_muslo_brazo_derecho', 'ind_muslo_brazo_izquierdo', 'itb_muslo', 'Índice muslo-brazo'),
            $par('segmentos_mp_p_der', 'segmentos_mp_p_izq', 'segmento_mp_p', 'Gradiente segmentario MP-P'),
            $par('segmentos_mp_md_der', 'segmentos_mp_md_izq', 'segmento_mp_md', 'Gradiente segmentario MP-MD'),
            $par('segmentos_t_p_der', 'segmentos_t_p_izq', 'segmento_t_p', 'Gradiente segmentario T-P'),
            $par('segmentos_t_md_der', 'segmentos_t_md_izq', 'segmento_t_md', 'Gradiente segmentario T-MD'),
            // Las dos casillas de reserva y compensación son `char(1)` (S/N) por lado.
            [
                ['clave' => 'reserva_der', 'etiqueta' => 'Reserva circulatoria derecha', 'grupo' => 'Reserva y compensación',
                    'tabla' => 'oscilometria', 'columna' => 'reserva_circulatoria_der', 'tipo' => 'si_no'],
                ['clave' => 'reserva_izq', 'etiqueta' => 'Reserva circulatoria izquierda', 'grupo' => 'Reserva y compensación',
                    'tabla' => 'oscilometria', 'columna' => 'reserva_circulatoria_izq', 'tipo' => 'si_no'],
                ['clave' => 'compensacion_der', 'etiqueta' => 'Compensación hemodinámica derecha', 'grupo' => 'Reserva y compensación',
                    'tabla' => 'oscilometria', 'columna' => 'compensacion_hemodinamica_der', 'tipo' => 'si_no'],
                ['clave' => 'compensacion_izq', 'etiqueta' => 'Compensación hemodinámica izquierda', 'grupo' => 'Reserva y compensación',
                    'tabla' => 'oscilometria', 'columna' => 'compensacion_hemodinamica_izq', 'tipo' => 'si_no'],
            ],
        );
    }

    /**
     * Los campos del marcapasos, agrupados como en la pantalla del escritorio.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function marcapasos(): array
    {
        $campo = fn (string $columna, string $etiqueta, ?string $grupo = null, string $tipo = 'texto', ?int $max = 30, ?string $unidad = null) => [
            'clave' => 'mp_' . $columna, 'etiqueta' => $etiqueta, 'grupo' => $grupo,
            'tabla' => 'implantacion_marcapasos', 'columna' => $columna, 'tipo' => $tipo, 'max' => $max, 'unidad' => $unidad,
        ];

        return [
            // Generador.
            $campo('marca', 'Marca', 'Generador'),
            $campo('modelo', 'Modelo', 'Generador'),
            $campo('serie', 'Serie', 'Generador'),
            $campo('serial', 'Serial', 'Generador'),
            $campo('modo', 'Modo', 'Generador', 'texto', 20),
            $campo('tipo_cable', 'Tipo de cable', 'Generador', 'texto', 10),
            $campo('camara_del_electrodo', 'Cámara del electrodo', 'Generador', 'texto', 10),
            // Programación.
            $campo('frecuencia_basica', 'Frecuencia básica', 'Programación', 'texto', 10),
            $campo('frecuencia_histeresis', 'Frecuencia de histéresis', 'Programación', 'texto', 10),
            $campo('periodo_refractario', 'Período refractario V.', 'Programación', 'texto', 10),
            $campo('periodo_refractario_a', 'Período refractario A.', 'Programación'),
            $campo('autocapture_ventricular', 'Autocapture ventricular', 'Programación', 'texto', 10),
            $campo('sencibilidad_respuesta_evocada', 'Sensibilidad de respuesta evocada', 'Programación', 'texto', 10),
            $campo('amplitud_impulso_v', 'Amplitud de impulso V', 'Programación', 'texto', 10),
            $campo('anchura_impulso_v', 'Anchura de impulso V', 'Programación', 'texto', 10),
            $campo('amplitud_impulso_a', 'Amplitud de impulso A', 'Programación'),
            $campo('anchura_impulso_a', 'Anchura de impulso A', 'Programación'),
            $campo('sencibilidad_pr', 'Sensibilidad P/R', 'Programación', 'texto', 10),
            $campo('configuracion_impulso_v', 'Configuración de impulso V', 'Programación', 'texto', 10),
            $campo('configuracion_deteccion_v', 'Configuración de detección V', 'Programación', 'texto', 10),
            $campo('vario', 'Vario', 'Programación', 'texto', 10),
            // Mediciones: umbrales, corrientes, resistencias y ondas.
            $campo('umbral_auricula', 'Umbral de aurícula', 'Mediciones', 'numero', null, 'V'),
            $campo('umbral_ventriculo', 'Umbral de ventrículo', 'Mediciones', 'numero', null, 'V'),
            $campo('corriente_auricula', 'Corriente de aurícula', 'Mediciones', 'numero', null, 'mA'),
            $campo('corriente_ventriculo', 'Corriente de ventrículo', 'Mediciones', 'numero', null, 'mA'),
            $campo('resistencia_auricula', 'Resistencia de aurícula', 'Mediciones', 'numero', null, 'ohmios'),
            $campo('resistencia_ventriculo', 'Resistencia de ventrículo', 'Mediciones', 'numero', null, 'ohmios'),
            $campo('onda_pr_auricula', 'Onda P/R de aurícula', 'Mediciones', 'numero', null, 'mV'),
            $campo('onda_pr_ventricula', 'Onda P/R de ventrículo', 'Mediciones', 'numero', null, 'mV'),
            $campo('ancho_pulso_auricula', 'Ancho de pulso de aurícula', 'Mediciones', 'numero', null, 'ms'),
            $campo('ancho_pulso_ventricula', 'Ancho de pulso de ventrículo', 'Mediciones', 'numero', null, 'ms'),
            // Implante y cierre.
            $campo('protocolo_implante', 'Protocolo de implante', 'Implante', 'parrafo', null),
            $campo('textazo', 'Texto del informe', 'Implante', 'parrafo', null),
            $campo('conclusiones', 'Conclusiones', 'Implante', 'parrafo', null),
        ];
    }

    /**
     * La grilla del Doppler venoso: seis venas × cuatro fases × dos lados, con la fila del escritorio como grupo.
     *
     * Las columnas del esquema son `<vena>_<fase><lado>`: `e` espontáneo, `f` fásico, `a` augmentado y `r` reflujo;
     * `1` derecha y `2` izquierda. Cada celda es texto corto (`char(2)`), como en `d_dd_venoso_miembros_inf.srd`.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function rejillaVenosa(): array
    {
        $venas = [
            'venas' => 'Venas',
            'fem_comun' => 'Femoral común',
            'fem_superf' => 'Femoral superficial',
            'poplitea' => 'Poplítea',
            'tibial_post' => 'Tibial posterior',
            'tibial_ante' => 'Tibial anterior',
            'safena' => 'Safena',
        ];
        $fases = ['e' => 'espontáneo', 'f' => 'fásico', 'a' => 'augmentado', 'r' => 'reflujo'];
        $lados = ['1' => 'derecha', '2' => 'izquierda'];

        $campos = [];

        foreach ($venas as $vena => $titulo) {
            foreach ($fases as $fase => $nombre) {
                foreach ($lados as $lado => $texto) {
                    $campos[] = [
                        'clave' => $vena . '_' . $fase . $lado,
                        'etiqueta' => ucfirst($nombre) . ' (' . $texto . ')',
                        'grupo' => $titulo,
                        'tabla' => 'ddflujo_venoso',
                        'columna' => $vena . '_' . $fase . $lado,
                        'tipo' => 'texto',
                        'max' => 2,
                    ];
                }
            }
        }

        return $campos;
    }

    /**
     * Las medidas del Doppler carotídeo, en pares **derecha / izquierda** como el DataWindow del escritorio.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function medidasCarotidas(): array
    {
        $par = function (string $colDer, string $colIzq, string $claveBase, string $etiqueta, ?string $unidad = 'cm/s') {
            $campos = [];

            foreach (['der' => ['derecha', $colDer], 'izq' => ['izquierda', $colIzq]] as $lado => [$texto, $columna]) {
                $campos[] = [
                    'clave' => $claveBase . '_' . $lado, 'etiqueta' => $etiqueta . ' (' . $texto . ')',
                    'tabla' => 'dd_arterias_carotidas_dos', 'columna' => $columna,
                    'tipo' => 'numero', 'unidad' => $unidad,
                ];
            }

            return $campos;
        };

        return array_merge(
            $par('ar_accp_der', 'ar_accp_izq', 'accp', 'Carótida común proximal (ACCp)'),
            $par('ar_accd_der', 'ar_accd_izq', 'accd', 'Carótida común distal (ACCd)'),
            $par('ar_ace_der', 'ar_ace_izq', 'ace', 'Carótida externa (ACE)'),
            $par('ar_aci_der', 'ar_aci_izq', 'aci', 'Carótida interna (ACI)'),
            // La razón ACI/ACC y el pico diastólico de la ACI tienen nombres propios en el esquema.
            [
                ['clave' => 'aci_acc_der', 'etiqueta' => 'Razón ACI/ACC (derecha)', 'tabla' => 'dd_arterias_carotidas_dos',
                    'columna' => 'ar_aci_acc_der', 'tipo' => 'numero', 'unidad' => null],
                ['clave' => 'aci_ace_izq', 'etiqueta' => 'Razón ACI/ACE (izquierda)', 'tabla' => 'dd_arterias_carotidas_dos',
                    'columna' => 'ar_aci_ace_izq', 'tipo' => 'numero', 'unidad' => null],
                ['clave' => 'pico_diastolico_der', 'etiqueta' => 'Pico diastólico de la ACI (derecha)', 'tabla' => 'dd_arterias_carotidas_dos',
                    'columna' => 'ar_carotida_interna_der', 'tipo' => 'numero', 'unidad' => 'cm/s'],
                ['clave' => 'pico_diastolico_izq', 'etiqueta' => 'Pico diastólico de la ACI (izquierda)', 'tabla' => 'dd_arterias_carotidas_dos',
                    'columna' => 'ar_carotida_interna_izq', 'tipo' => 'numero', 'unidad' => 'cm/s'],
            ],
            $par('av_diametro_der', 'av_diametro_izq', 'vertebral_diametro', 'Diámetro de la vertebral (AV)', 'mm'),
            $par('av_veloci_sp_der', 'av_veloci_sp_izq', 'vertebral_velocidad', 'Velocidad sistólica de la vertebral (AV)'),
            $par('av_direccionf_der', 'av_direccionf_izq', 'vertebral_direccion', 'Dirección del flujo de la vertebral (AV)', null),
            // R.I.M.: el sufijo `_d` del esquema es la derecha; el otro es la izquierda.
            [
                ['clave' => 'rim_der', 'etiqueta' => 'R.I.M.D. (grosor íntima-media derecho)', 'tabla' => 'dd_arterias_carotidas_dos',
                    'columna' => 'relac_intima_media_d', 'tipo' => 'numero', 'unidad' => 'mm'],
                ['clave' => 'rim_izq', 'etiqueta' => 'R.I.M.I. (grosor íntima-media izquierdo)', 'tabla' => 'dd_arterias_carotidas_dos',
                    'columna' => 'relac_intima_media', 'tipo' => 'numero', 'unidad' => 'mm'],
            ],
        );
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

    /** ¿La clave es la sección de los estudios cardiológicos? */
    public static function esSeccion(?string $seccion): bool
    {
        return array_key_exists((string) $seccion, self::TITULOS);
    }

    /** El rótulo de un campo por su columna (para los mensajes de «no se pudo guardar»). */
    public static function etiquetaDe(string $columna): string
    {
        foreach (self::campos() as $campos) {
            foreach ($campos as $campo) {
                if ($campo['columna'] === $columna) {
                    return $campo['etiqueta'];
                }
            }
        }

        return $columna;
    }
}
