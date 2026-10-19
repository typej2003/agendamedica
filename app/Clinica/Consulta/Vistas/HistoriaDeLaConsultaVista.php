<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **La historia clínica dentro de la consulta** (pestaña de *Solicitudes*, 2026-10-19): la hoja que el escritorio
 * muestra en **vista previa** al abrir esa pestaña y que se imprime con su botón (`tabpage_15` de `tab_4`,
 * `w_consulta_principal.srw`: el DataWindow `d_historia_clinica_dos`, que en el fuente se pone en `Print.Preview` al
 * seleccionar la solapa; en pediatría la solapa se llama *Historia Pediátrica*).
 *
 * La web **no duplica la hoja**: la pestaña ofrece la que ya existe —`/clinica/pacientes/{paciente}/historia/imprimir`,
 * el resumen de las consultas con el membrete del médico— con «Imprimir» y «Visualizar» (el diálogo de vista previa de
 * la consulta, WEB-11.24), que es exactamente lo que hace el escritorio. Y el enlace a la pantalla de la historia para
 * trabajar con ella (documentos, antecedentes, consultas).
 *
 * Es de **sólo lectura**: no escribe filas ni deja rastro en el sync. Sin base de datos.
 */
final class HistoriaDeLaConsultaVista
{
    /**
     * @param  string  $paciente  el nombre del paciente, para el texto de la pestaña
     * @param  string|null  $urlImprimir  la hoja de la historia, o `null` si el paciente no tiene número de historia
     * @param  string  $urlHistoria  la pantalla de la historia del paciente
     */
    public function __construct(
        private string $paciente,
        private ?string $urlImprimir,
        private string $urlHistoria,
        private string $rotulo = 'Historia clínica',
    ) {
    }

    /** El rótulo de la pestaña y de la tarjeta: *Historia clínica* o, en pediatría, *Historia pediátrica*. */
    public function rotulo(): string
    {
        return $this->rotulo;
    }

    /** ¿Hay hoja que imprimir? Sin número de historia no hay consultas que resumir. */
    public function puedeImprimir(): bool
    {
        return $this->urlImprimir !== null;
    }

    public function urlImprimir(): string
    {
        return (string) $this->urlImprimir;
    }

    public function urlHistoria(): string
    {
        return $this->urlHistoria;
    }

    public function ayuda(): string
    {
        return 'La ' . mb_strtolower($this->rotulo) . ' de ' . $this->paciente . ' tal como se imprime: el membrete del '
            . 'médico y, consulta por consulta, lo que se atendió. «Visualizar» la muestra acá mismo y «Imprimir» abre la '
            . 'hoja para imprimirla. Para trabajar con ella —documentos, antecedentes, consultas— abrila desde Pacientes.';
    }

    /** El aviso cuando el paciente todavía no tiene número de historia (no hay hoja que ofrecer). */
    public function sinHistoria(): string
    {
        return 'Este paciente todavía no tiene número de historia, así que no hay una hoja que imprimir. Se lo asigna '
            . 'el servidor cuando se complete la historia o al atenderlo.';
    }
}
