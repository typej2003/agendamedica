<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **I.Q.** (cirugía pediátrica, 2026-10-19): el último ítem del árbol de PediaReport (`tabpage_20`) con sus tres
 * solapas.
 *
 * Están la **evaluación pre-operatoria** (`pre_aneste`, una fila por consulta) y las **intervenciones**
 * (`interven_consulta`, una fila por intervención). El **riesgo quirúrgico** queda anunciado como pendiente.
 */
final class QuirurgicoVista
{
    public const INTERVENCIONES = 'intervenciones';
    public const PREOPERATORIA = 'preoperatoria';
    public const RIESGO = 'riesgo';

    /**
     * @param  array<int,array{clave:string, etiqueta:string, clase:string, valor:string, ayuda:?string}>  $campos
     * @param  array<int,array{clave:string, texto:string, url:string, activa:bool, cargada:bool, pendiente:bool}>  $pestanas
     * @param  array<int,array{codigo:string, descripcion:string, detalle:string, urlQuitar:string}>  $intervenciones
     */
    public function __construct(
        private string $pestana,
        private array $campos,
        private array $pestanas,
        private array $intervenciones,
        private string $urlGuardar,
        private string $urlVolver = '',
        private bool $cargada = false,
        private bool $emergencia = false,
        private string $urlAgregarIntervencion = '',
    ) {
    }

    public function pestana(): string
    {
        return $this->pestana;
    }

    public function esPestana(string $clave): bool
    {
        return $this->pestana === $clave;
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

    /** @return array<int,array{codigo:string, descripcion:string, detalle:string, urlQuitar:string}> */
    public function intervenciones(): array
    {
        return $this->intervenciones;
    }

    public function tieneIntervenciones(): bool
    {
        return $this->intervenciones !== [];
    }

    public function urlAgregarIntervencion(): string
    {
        return $this->urlAgregarIntervencion;
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
        return $this->esPestana(self::INTERVENCIONES) ? 'Intervenciones' : 'Evaluación pre-operatoria';
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
        return $this->esPestana(self::INTERVENCIONES)
            ? 'Las intervenciones de la consulta, con su código, su descripción y el detalle. Se guardan de a una y se '
                . 'pueden quitar; el riesgo quirúrgico llega en la próxima entrega.'
            : 'Es la evaluación pre-operatoria del escritorio: el tipo, el resultado y si es una emergencia. Se guarda '
                . 'en la consulta y se vuelve a mostrar cuando la abrís de nuevo.';
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay datos escritos sin guardar. Si sales ahora, se pierden.';
    }
}
