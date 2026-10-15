<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EstudioEcografico;

/**
 * **El eco mamario en la sección de estudio** (WEB-6.4): el formulario de sus tres bloques y el estado de lo
 * que tiene cargado.
 *
 * Hereda de `EcoObstetricoVista` (WEB-6.2) porque el estudio tiene la misma forma: una fila por consulta, los
 * campos del **mapa del dato** (`EcoMamario::grupos()`), el mismo estado por campos cargados y la misma regla
 * de "esto es un documento" (`QueEsUnDocumento::ecografia`). Lo que cambia:
 *
 *  - **Su mapa, su tabla (llaveada por `historia` + `consulta`) y su ficha**: la del mamario es sólo la fecha,
 *    así que la cuenta de campos cargados excluye **una** columna y no tres. Los cuatro campos cortos (el
 *    transductor, la menarquia, los quirúrgicos y las hormonas) **sí** son hallazgos del estudio.
 *  - **El desplegable de médicos**: `referido` guarda la cédula de un colega del catálogo `doctores` y sus
 *    opciones no salen del mapa (el legado no tiene ahí ningún código) sino de la **libreta del médico**
 *    (`DoctoresDelMedico`), que el controlador lee y le pasa ya resuelta. La opción vacía va adelante
 *    (trampa 112), como en todos los desplegables de la sección.
 *  - El **informe en texto libre** del escritorio (`puro_texto`, su modo «Plantillas») se muestra y no se
 *    edita, pero **no cuenta como documento**: la hoja del mamario no tiene párrafos de texto libre (su modo
 *    «Plantillas» es otro DataWindow de impresión, que la web no imprime), así que la regla de la base —los
 *    campos del estudio— es la que vale.
 */
final class EcoMamarioVista extends EcoObstetricoVista
{
    /**
     * @param  array<int,array{valor:string,texto:string}>  $medicos  la libreta del médico (`DoctoresDelMedico`),
     *                                                                ya como opciones `cédula => nombre`
     */
    public function __construct(
        EstudioEcografico $estudio,
        string $urlGuardar = '',
        string $urlVolver = '',
        string $urlImprimir = '',
        private array $medicos = [],
        array $old = [],
    ) {
        parent::__construct($estudio, $urlGuardar, $urlVolver, $urlImprimir, null, null, $old);
    }

    /**
     * El mapa del dato con las opciones del desplegable de médicos puestas: el mapa las declara **vacías**
     * (el legado no tiene ahí ningún código) y la libreta del médico llega por el constructor.
     */
    protected function mapa(): array
    {
        $grupos = EcoMamario::grupos();

        foreach ($grupos as $posicion => $grupo) {
            foreach ($grupo['campos'] as $indice => $campo) {
                if ($campo['columna'] === EcoMamario::COLUMNA_REFERIDO) {
                    $grupos[$posicion]['campos'][$indice]['opciones'] = $this->medicos;
                }
            }
        }

        return $grupos;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoMamario::columnas();
    }

    /**
     * La ficha del mamario: **sólo la fecha** (el servidor la completa con hoy). No tiene FUR ni gesta —es el
     * único de los ecos que no es del embarazo—.
     *
     * @return array<int,string>
     */
    protected function columnasQueNoCuentan(): array
    {
        return EcoMamario::COLUMNAS_QUE_NO_CUENTAN;
    }

    public function titulo(): string
    {
        return 'Eco mamario';
    }

    public function icono(): string
    {
        return TiposDeEstudio::icono(TiposDeEstudio::MAMARIO);
    }

    /** La ayuda de la pantalla: qué es el estudio y qué pasa al salir sin guardar. */
    public function ayuda(): string
    {
        return 'El eco mamario: el transductor, la menarquia, los antecedentes quirúrgicos y hormonales, y los '
            . 'hallazgos de cada mama y cada axila con su conclusión. Se guarda con el botón: si sales sin '
            . 'guardar, el navegador te avisa antes de perder lo escrito.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el eco mamario. Si sales ahora, se pierden.';
    }

    /**
     * El informe en texto libre que dejó el escritorio (`puro_texto`, su modo «Plantillas»), o `null`. La web
     * lo muestra y **no** lo edita.
     *
     * ⚠️ A diferencia del eco pélvico y del Doppler, este texto **no es un documento**: la hoja del mamario no
     * tiene párrafos de texto libre (el modo «Plantillas» es **otro** DataWindow de impresión,
     * `d_ultasonido_mamas_print_puro`, que la web no imprime). Por eso el ViewModel **no** redefine
     * `esUnDocumento()`: la regla de la base —los campos del estudio, sin la fecha— es la misma de la Action y
     * de la hoja, y una fila que sólo trae `puro_texto` se muestra pero no ofrece un informe vacío.
     */
    public function puroTexto(): ?string
    {
        return $this->estudio->valor(EcoMamario::COLUMNA_PURO_TEXTO);
    }

    /** ¿El escritorio dejó un informe en texto libre? */
    public function hayPuroTexto(): bool
    {
        return $this->puroTexto() !== null;
    }
}
