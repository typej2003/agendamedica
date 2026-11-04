<?php

namespace App\Clinica\Consulta\Cardiologia;

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

    /** Las pestañas de la sección: el eco transtorácico (CAR-2), el de estrés y el ETE (CAR-3). */
    public const PESTANA_ECO = 'eco';
    public const PESTANA_ESTRES = 'estres';
    public const PESTANA_ETE = 'ete';

    /**
     * Las tablas de una fila por consulta: tabla => [modelo, columna de la historia, columna de la consulta].
     *
     * @var array<string,array{0:class-string, 1:string, 2:string}>
     */
    public const TABLAS = [
        'ecocardiografia' => [Ecocardiografia::class, 'numhistoria', 'numconsulta'],
        'ecocardiografico_in' => [EcocardiograficoInforme::class, 'numhistoria', 'numconsulta'],
        'ecocardiograma_ete' => [EcocardiogramaEte::class, 'numhistoria', 'numconsulta'],
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
