<?php

namespace App\Clinica\Consulta\Cardiologia;

use App\Models\Ecocardiografia;

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
 * CAR-2 entra con el **ecocardiograma transtorácico**; las demás pestañas se suman con su ola, sin tocar esta.
 *
 * Cada campo: `clave` (el nombre en el formulario), `etiqueta`, `tabla`, `columna`, `tipo` (`texto`, `parrafo`) y
 * `max` cuando la columna del legado tiene largo.
 */
final class MapaDelCardiologo
{
    /** La sección de los estudios cardiológicos dentro de la consulta (`?seccion=cardiologia`). */
    public const SECCION = 'cardiologia';

    /** Las pestañas de la sección (CAR-2: una sola; las demás olas suman las suyas). */
    public const PESTANA_ECO = 'eco';

    /**
     * Las tablas de una fila por consulta: tabla => [modelo, columna de la historia, columna de la consulta].
     *
     * @var array<string,array{0:class-string, 1:string, 2:string}>
     */
    public const TABLAS = [
        'ecocardiografia' => [Ecocardiografia::class, 'numhistoria', 'numconsulta'],
    ];

    /**
     * Las pestañas por sección: seccion => [pestaña => título]. La primera es la de entrada.
     *
     * @var array<string,array<string,string>>
     */
    public const PESTANAS = [
        self::SECCION => [
            self::PESTANA_ECO => 'Ecocardiograma',
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
