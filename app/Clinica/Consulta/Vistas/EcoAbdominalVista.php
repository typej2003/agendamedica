<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EstudioEcografico;

/**
 * **El eco abdominal en la sección de estudio** (WEB-6.4): el formulario de sus cuatro bloques y el estado de
 * lo que tiene cargado.
 *
 * Hereda de `EcoObstetricoVista` (WEB-6.2) porque el estudio tiene la misma forma: una fila por consulta, los
 * campos del **mapa del dato** (`EcoAbdominal::grupos()`), el mismo estado por campos cargados y la misma
 * regla de "esto es un documento" (`QueEsUnDocumento::ecografia`). Lo que cambia:
 *
 *  - **Su mapa, su tabla (llaveada por `historia` + `consulta`) y su ficha**: la del abdominal es sólo la
 *    fecha, así que la cuenta de campos cargados excluye **una** columna y no tres (como el mamario). El
 *    transductor **sí** es un dato del estudio.
 *  - **Los cuatro desplegables con códigos** (`vol_res_v_p_m`, `ascitis`, `retroperitoneo` y
 *    `vasos_sanguineos`), que salen del mapa con la opción vacía adelante (trampa 112) y el código
 *    desconocido del escritorio al final, como todos los de la sección.
 *  - **El desplegable de médicos**: `referido` guarda la cédula de un colega del catálogo `doctores` y sus
 *    opciones no salen del mapa (el legado no tiene ahí ningún código) sino de la **libreta del médico**
 *    (`DoctoresDelMedico`), que el controlador lee y le pasa ya resuelta.
 *  - El **informe en texto libre** del escritorio (`puro_texto`, su modo «Plantillas») se muestra y no se
 *    edita, pero **no cuenta como documento**: la hoja del abdominal no tiene párrafos de texto libre (su
 *    modo «Plantillas» es otro DataWindow de impresión, `d_ultasonido_abdominal_print_puro`, que la web no
 *    imprime), así que la regla de la base —los campos del estudio— es la que vale.
 *
 * ⚠️ **`prostata` y `vejiga_urina` no están en el mapa**: son de urología y en ginecología van ocultas en el
 * escritorio. La pantalla no las ofrece y la Action no las toca.
 */
final class EcoAbdominalVista extends EcoObstetricoVista
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
        $grupos = EcoAbdominal::grupos();

        foreach ($grupos as $posicion => $grupo) {
            foreach ($grupo['campos'] as $indice => $campo) {
                if ($campo['columna'] === EcoAbdominal::COLUMNA_REFERIDO) {
                    $grupos[$posicion]['campos'][$indice]['opciones'] = $this->medicos;
                }
            }
        }

        return $grupos;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoAbdominal::columnas();
    }

    /**
     * La ficha del abdominal: **sólo la fecha** (el servidor la completa con hoy). No tiene FUR ni gesta: es
     * un estudio general, no del embarazo.
     *
     * @return array<int,string>
     */
    protected function columnasQueNoCuentan(): array
    {
        return EcoAbdominal::COLUMNAS_QUE_NO_CUENTAN;
    }

    public function titulo(): string
    {
        return 'Eco abdominal';
    }

    public function icono(): string
    {
        return TiposDeEstudio::icono(TiposDeEstudio::ABDOMINAL);
    }

    /** La ayuda de la pantalla: qué es el estudio y qué pasa al salir sin guardar. */
    public function ayuda(): string
    {
        return 'El eco abdominal: el hígado, la vesícula y la vía biliar, el páncreas, los dos riñones, el '
            . 'volumen residual, la ascitis y los vasos, con sus observaciones y conclusiones. Se guarda con el '
            . 'botón: si sales sin guardar, el navegador te avisa antes de perder lo escrito.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el eco abdominal. Si sales ahora, se pierden.';
    }

    /**
     * El informe en texto libre que dejó el escritorio (`puro_texto`, su modo «Plantillas»), o `null`. La web
     * lo muestra y **no** lo edita.
     *
     * ⚠️ Este texto **no es un documento**: la hoja del abdominal no tiene párrafos de texto libre (el modo
     * «Plantillas» es **otro** DataWindow de impresión, `d_ultasonido_abdominal_print_puro`, que la web no
     * imprime). Por eso el ViewModel **no** redefine `esUnDocumento()`: la regla de la base —los campos del
     * estudio, sin la fecha— es la misma de la Action y de la hoja.
     */
    public function puroTexto(): ?string
    {
        return $this->estudio->valor(EcoAbdominal::COLUMNA_PURO_TEXTO);
    }

    /** ¿El escritorio dejó un informe en texto libre? */
    public function hayPuroTexto(): bool
    {
        return $this->puroTexto() !== null;
    }
}
