<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EstudioEcografico;

/**
 * **El ecocardiograma fetal en la sección de estudio** (WEB-6.3): el formulario de sus seis bloques y el
 * estado de lo que tiene cargado, más la **edad gestacional y la FPP**, que el escritorio calcula de la FUR.
 *
 * Hereda de `EcoObstetricoVista` (WEB-6.2) porque el estudio tiene la misma forma: una fila por consulta, los
 * campos del **mapa del dato** (`EcoCardiogramaFetal::grupos()`), el mismo estado por campos cargados y la
 * misma regla de "esto es un documento" (`QueEsUnDocumento::ecografia`). Lo que cambia:
 *
 *  - **Su mapa, su tabla y su ficha**: la ficha del ecocardiograma es más ancha que la de los obstétricos —la
 *    fecha, la FUR, la gesta y la paridad (el `para`, los abortos, las cesáreas y los `otros`)—, así que la
 *    cuenta de campos cargados excluye siete columnas y no tres.
 *  - **La FPP y la EG**, que la pantalla muestra calculadas de la FUR: las hereda de `EcoObstetricoVista`
 *    (su `fpp()` y su `edadGestacional()`), con la constante única de `EdadGestacional`: la regla está
 *    escrita una sola vez y es la misma de los ecos obstétricos.
 *  - **La precarga del escritorio** (los dos memos) la resuelve la lectura (`deParaLaPantalla`), no este
 *    ViewModel; lo que este agrega es que **la hoja no se ofrece hasta que el estudio exista**: una consulta
 *    sin fila muestra los textos precargados, pero no hay nada guardado que imprimir.
 */
final class EcoCardiogramaFetalVista extends EcoObstetricoVista
{
    /**
     * @param  bool  $hayFila  ¿la consulta ya tiene su fila de `ecocadiograma_fetal`? La pantalla abre una
     *                         consulta **sin fila** con la precarga del escritorio, y la hoja se arma de lo
     *                         guardado: sin fila no se ofrece (sería un 404).
     */
    public function __construct(
        EstudioEcografico $estudio,
        string $urlGuardar = '',
        string $urlVolver = '',
        string $urlImprimir = '',
        ?string $furSugerida = null,
        ?int $gestaSugerida = null,
        array $old = [],
        private bool $hayFila = true,
        ?string $fechaPorDefecto = null,
    ) {
        parent::__construct($estudio, $urlGuardar, $urlVolver, $urlImprimir, $furSugerida, $gestaSugerida, $old, $fechaPorDefecto);
    }

    protected function mapa(): array
    {
        return EcoCardiogramaFetal::grupos();
    }

    protected function columnasDelEstudio(): array
    {
        return EcoCardiogramaFetal::columnas();
    }

    /**
     * La ficha del ecocardiograma: la fecha, la FUR, la gesta y **la paridad** (`para`, `aborto`, `cesarea` y
     * `otros`), que son del encabezado del informe y no hallazgos del estudio.
     *
     * @return array<int,string>
     */
    protected function columnasQueNoCuentan(): array
    {
        return EcoCardiogramaFetal::COLUMNAS_QUE_NO_CUENTAN;
    }

    public function titulo(): string
    {
        return 'Ecocardiograma fetal';
    }

    /**
     * ¿El estudio **existe** y dice algo? La pantalla abre una consulta sin fila con la precarga del escritorio,
     * pero esos dos textos no son todavía un informe: la hoja se arma de lo **guardado**
     * (`EcoCardiogramaFetal::de`), así que ofrecerla antes de guardar llevaría a un 404. Sin fila, no se ofrece.
     */
    public function esUnDocumento(): bool
    {
        return $this->hayFila && parent::esUnDocumento();
    }

    /**
     * El estado de la pestaña: sin fila, el estudio está **precargado pero sin guardar** (decir «Sin cargar»
     * cuando los dos memos están escritos en pantalla sería contradictorio).
     *
     * @return array{tono:string, icono:string, texto:string, titulo:string}
     */
    public function estado(): array
    {
        if (! $this->hayFila) {
            return ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin guardar',
                'titulo' => 'Todavía no hay nada guardado'];
        }

        return parent::estado();
    }

    public function icono(): string
    {
        return TiposDeEstudio::icono(TiposDeEstudio::FETAL);
    }

    /** La ayuda de la pantalla: qué es el estudio y de dónde salen la EG y la FPP. */
    public function ayuda(): string
    {
        return 'El ecocardiograma fetal: la biometría, la anatomía del corazón, la placenta y el cordón. La EG '
            . 'y la FPP se calculan solas de la FUR. Se guarda con el botón: si sales sin guardar, el '
            . 'navegador te avisa antes de perder lo escrito.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el ecocardiograma fetal. Si sales ahora, se pierden.';
    }
}
