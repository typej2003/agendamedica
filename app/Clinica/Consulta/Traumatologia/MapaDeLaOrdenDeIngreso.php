<?php

namespace App\Clinica\Consulta\Traumatologia;

/**
 * **El mapa de la orden de ingreso** (Traumatología, T3, 2026-11-10): las columnas de `orden_ingreso` con el rótulo de
 * la ventana (`d_orden_ingreso_3.srd`) y de la hoja «ORDEN DE INGRESO».
 *
 * Es **una fila por consulta** (`historia` + `consulta`). `idx` es el **DX**, `texto` las **recomendaciones** escritas,
 * `detalle` las **observaciones** y las catorce `reco_*` las casillas fijas de la hoja (depilado, Cedroxim, Clexane…);
 * las casillas son `char(1)` con **`1`/`0`**, como las de la plantilla ortopédica.
 */
final class MapaDeLaOrdenDeIngreso
{
    public const SECCION = 'orden-ingreso';

    public const TABLA = 'orden_ingreso';

    /** El rótulo de la pestaña en *Solicitudes*. */
    public const TITULO = 'Orden de ingreso';

    /**
     * Las casillas de la hoja, en su orden, con el texto que imprime el escritorio.
     *
     * @return array<string,string> columna => rótulo
     */
    public static function recomendaciones(): array
    {
        return [
            'reco_ingresar' => 'Ingresar 12 h < IQ',
            'reco_depi' => 'Depilado o afeitado periné Ms',
            'reco_llevar' => 'Llevar los estudios al ingreso',
            'reco_cedroxin' => 'Cedroxim, 500 mg BID',
            'reco_ejercicios' => 'Ejercicios MsIs',
            'reco_terapia' => 'Terapia ventilatoria Triflow II',
            'reco_control' => 'Control signos c/6 h',
            'reco_higiene' => 'Higiene personal 2-3 v/d',
            'reco_ropa' => 'Ropa holgada para facilitar tto',
            'reco_tomar' => 'Tomar la medición regular',
            'reco_clexane' => 'Clexane 20 mg SC OD',
            'reco_ejercicios_res' => 'Ejercicios respiratorios',
            'reco_sangre' => '1000 cc Sangre completa',
            'reco_avisar' => 'Avisar anormalidad al suscrito',
        ];
    }

    /**
     * Los grupos de la ventana, con sus campos.
     *
     * @return array<string,array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int}>>
     */
    public static function grupos(): array
    {
        $recomendaciones = [];

        foreach (self::recomendaciones() as $columna => $rotulo) {
            $recomendaciones[] = ['clave' => $columna, 'etiqueta' => $rotulo, 'columna' => $columna, 'tipo' => 'marca'];
        }

        return [
            'Datos de la intervención' => [
                ['clave' => 'fecha_operacion', 'etiqueta' => 'Fecha de la intervención', 'columna' => 'fecha_operacion', 'tipo' => 'fecha'],
                ['clave' => 'fecha_reporte', 'etiqueta' => 'Fecha', 'columna' => 'fecha_reporte', 'tipo' => 'fecha'],
                ['clave' => 'idx', 'etiqueta' => 'DX', 'columna' => 'idx', 'tipo' => 'parrafo'],
                ['clave' => 'texto', 'etiqueta' => 'Recomendaciones', 'columna' => 'texto', 'tipo' => 'parrafo'],
            ],
            'Recomendaciones de la hoja' => $recomendaciones,
            'Observaciones' => [
                ['clave' => 'detalle', 'etiqueta' => 'Observaciones', 'columna' => 'detalle', 'tipo' => 'parrafo'],
            ],
        ];
    }

    /**
     * Los campos, en el orden de los grupos.
     *
     * @return array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int}>
     */
    public static function campos(): array
    {
        return array_merge(...array_values(self::grupos()));
    }

    /** Las columnas de la tabla que escriben estos campos. */
    public static function columnas(): array
    {
        return array_column(self::campos(), 'columna');
    }
}
