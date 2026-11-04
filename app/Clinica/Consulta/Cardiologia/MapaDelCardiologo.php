<?php

namespace App\Clinica\Consulta\Cardiologia;

use App\Models\DopplerCarotidas;
use App\Models\DopplerMiembrosSuperiores;
use App\Models\DopplerVenoso;
use App\Models\Ecocardiografia;
use App\Models\EcocardiogramaEte;
use App\Models\EcocardiograficoInforme;

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
