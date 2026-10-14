<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\QueEsUnDocumento;

/**
 * **El eco Doppler perinatal en la sección de estudio** (WEB-6.3): el formulario de sus cuatro bloques
 * —la ficha, la grilla de cuatro vasos, los tres desplegables y las áreas de texto— y el estado de lo que
 * tiene cargado.
 *
 * Hereda de `EcoObstetricoVista` (WEB-6.2) porque el estudio tiene la misma forma: una fila por consulta, los
 * campos del **mapa del dato** (`EcoDoppler::grupos()`), el mismo estado por campos cargados y la misma regla
 * de "esto es un documento" (`QueEsUnDocumento::ecografia`). Lo que cambia:
 *
 *  - **Su mapa, su tabla (llaveada por `historia` + `consulta`) y su ficha**: el `para` también es de la ficha
 *    y no cuenta como hallazgo, así que la cuenta de campos cargados excluye cuatro columnas y no tres.
 *  - **La grilla de cuatro vasos**: el bloque se arma por fila (la arteria) y por eje (la columna), con la
 *    estructura que le da `grillaDelBloque()`. Es lo único que este ViewModel agrega al formulario.
 *  - El **informe en texto libre** del escritorio (`puro_texto`, su modo «Plantillas») se muestra y no se
 *    edita, igual que en el eco pélvico de WEB-6.1.
 */
final class EcoDopplerVista extends EcoObstetricoVista
{
    protected function mapa(): array
    {
        return EcoDoppler::grupos();
    }

    protected function columnasDelEstudio(): array
    {
        return EcoDoppler::columnas();
    }

    protected function columnasQueNoCuentan(): array
    {
        return EcoDoppler::COLUMNAS_QUE_NO_CUENTAN;
    }

    public function titulo(): string
    {
        return 'Eco Doppler perinatal';
    }

    public function icono(): string
    {
        return TiposDeEstudio::icono(TiposDeEstudio::DOPPLER);
    }

    /**
     * Los bloques del formulario, con el valor de cada campo ya resuelto y —el de la grilla— además armado
     * por fila y por eje, que es lo que la vista recorre para pintar la tabla.
     *
     * @return array<int,array<string,mixed>>
     */
    public function grupos(): array
    {
        $grupos = parent::grupos();
        $mapa = $this->mapa();

        foreach ($grupos as $posicion => $grupo) {
            $grupos[$posicion]['grilla'] = ($mapa[$posicion]['grilla'] ?? false) === true
                ? $this->grillaDelBloque($grupo['campos'])
                : null;
        }

        return $grupos;
    }

    /** La ayuda de la pantalla: qué es el estudio y qué pasa al salir sin guardar. */
    public function ayuda(): string
    {
        return 'El eco Doppler perinatal: el flujo de las dos arterias uterinas, la umbilical y la cerebral '
            . 'media, más los ductos venosos y la relación aorta-cava. Se guarda con el botón: si sales sin '
            . 'guardar, el navegador te avisa antes de perder lo escrito.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el eco Doppler. Si sales ahora, se pierden.';
    }

    /**
     * El informe en texto libre que dejó el escritorio (`puro_texto`, su modo «Plantillas»), o `null`. La web
     * lo muestra y **no** lo edita, como el del eco pélvico.
     */
    public function puroTexto(): ?string
    {
        return $this->estudio->valor(EcoDoppler::COLUMNA_PURO_TEXTO);
    }

    /** ¿El escritorio dejó un informe en texto libre? */
    public function hayPuroTexto(): bool
    {
        return $this->puroTexto() !== null;
    }

    /**
     * ¿El estudio es un documento? Es **la misma regla que la impresión** (`QueEsUnDocumento::ecografia`), con
     * el informe en texto libre del escritorio incluido: `puro_texto` no está entre las columnas que la
     * pantalla edita, así que sin este override la pantalla no ofrecería la hoja de un estudio que el motor de
     * documentos sí imprime. Es lo que hace el eco pélvico (WEB-6.1).
     */
    public function esUnDocumento(): bool
    {
        return QueEsUnDocumento::ecografia($this->cargados(), $this->hayPuroTexto());
    }

    /**
     * La grilla de cuatro vasos de un bloque, ya con los valores resueltos: sus **ejes** (las cuatro columnas,
     * iguales en todas las filas) y sus **filas**, cada una con la arteria y sus cuatro celdas.
     *
     * @param  array<int,array<string,mixed>>  $campos  los campos ya resueltos de ese bloque
     * @return array{ejes:array<int,string>, filas:array<int,array{etiqueta:string, campos:array<int,array<string,mixed>>}>}
     */
    private function grillaDelBloque(array $campos): array
    {
        $filas = [];

        foreach ($campos as $campo) {
            $filas[$campo['fila']]['etiqueta'] = $campo['fila'];
            $filas[$campo['fila']]['campos'][] = $campo;
        }

        return ['ejes' => EcoDoppler::ejesDeLaGrilla(), 'filas' => array_values($filas)];
    }
}
