<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **I.Q.** (cirugía pediátrica, 2026-10-19): el ítem del árbol de PediaReport (`tabpage_20`) con sus solapas.
 *
 * Esta primera entrega es la **evaluación pre-operatoria** (`tabpage_32` / `d_pre_aneste`); las otras dos
 * —intervenciones (`interven_consulta`) y riesgo quirúrgico (`riesgo_paciente` sobre el catálogo `riesgo`)— quedan
 * como pestañas pendientes, y el ViewModel ya las lleva para que se sumen sin tocar el contenedor.
 */
final class QuirurgicoVista
{
    /**
     * @param  array<int,array{clave:string, etiqueta:string, clase:string, valor:string, ayuda:?string}>  $campos
     * @param  array<int,array{clave:string, texto:string, url:string, activa:bool, cargada:bool, pendiente:bool}>  $pestanas
     */
    public function __construct(
        private array $campos,
        private array $pestanas,
        private string $urlGuardar,
        private string $urlVolver = '',
        private bool $cargada = false,
        private bool $emergencia = false,
    ) {
    }

    /** @return array<int,array{clave:string, etiqueta:string, clase:string, valor:string, ayuda:?string}> */
    public function campos(): array
    {
        return $this->campos;
    }

    /** @return array<int,array{clave:string, texto:string, url:string, activa:bool, cargada:bool, pendiente:bool}> */
    public function pestanas(): array
    {
        return $this->pestanas;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function cargada(): bool
    {
        return $this->cargada;
    }

    /** Si la evaluación está marcada como emergencia, la pantalla lo avisa. */
    public function esEmergencia(): bool
    {
        return $this->emergencia;
    }

    public function titulo(): string
    {
        return 'Evaluación pre-operatoria';
    }

    public function estado(): array
    {
        return $this->cargada
            ? ['tono' => $this->emergencia ? 'aviso' : 'exito', 'icono' => $this->emergencia ? 'bi-exclamation-triangle' : 'bi-check2-circle',
                'texto' => $this->emergencia ? 'Emergencia' : 'Cargada',
                'titulo' => $this->emergencia ? 'La evaluación está marcada como emergencia' : 'La consulta ya tiene la evaluación guardada']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay evaluación pre-operatoria en esta consulta'];
    }

    public function ayuda(): string
    {
        return 'Es la evaluación pre-operatoria del escritorio: el tipo, el resultado y si es una emergencia. Se guarda '
            . 'en la consulta y se vuelve a mostrar cuando la abrís de nuevo. Las intervenciones y el riesgo quirúrgico '
            . 'llegan en la próxima entrega de esta sección.';
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay datos escritos sin guardar en la evaluación pre-operatoria. Si sales ahora, se pierden.';
    }
}
