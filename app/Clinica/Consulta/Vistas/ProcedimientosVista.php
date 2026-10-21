<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **Procedimientos** (gastroenterología pediátrica, 2026-10-19): el ítem 8 del árbol de PediaReport, con sus dos
 * endoscopías digestivas como pestañas.
 *
 * El ViewModel no sabe de base de datos: recibe las pestañas y los campos ya resueltos (el mapa está en
 * `EndoscopiaDigestiva`). Los campos se pintan por `clase`: `fecha`, `texto`, `parrafo` u `opcion` (un estado de un
 * carácter, que es `char(1)` en el esquema del legado).
 */
final class ProcedimientosVista
{
    /**
     * @param  string  $titulo  el título de la endoscopía activa
     * @param  array<int,array{clave:string, etiqueta:string, clase:string, valor:string, ayuda:?string}>  $campos
     * @param  array<int,array{clave:string, texto:string, url:string, activa:bool, cargada:bool}>  $pestanas
     * @param  string  $urlGuardar  a dónde postea el formulario (lleva el tipo en la ruta)
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de la guía de UI)
     * @param  bool  $cargada  si la endoscopía activa ya tiene datos
     * @param  EcoAbdominalVista|null  $eco  el eco abdominal, cuando la pestaña activa es la suya: el ítem
     *                                       «Procedimientos» del escritorio también abre `w_cargar_visualizar_eco_abdominal`
     */
    public function __construct(
        private string $titulo,
        private array $campos,
        private array $pestanas,
        private string $urlGuardar,
        private string $urlVolver = '',
        private bool $cargada = false,
        private ?EcoAbdominalVista $eco = null,
    ) {
    }

    /** ¿La pestaña activa es la del eco abdominal? (entonces el parcial que se pinta es el del estudio) */
    public function esEcoAbdominal(): bool
    {
        return $this->eco !== null;
    }

    public function eco(): ?EcoAbdominalVista
    {
        return $this->eco;
    }

    public function titulo(): string
    {
        return $this->titulo;
    }

    /** @return array<int,array{clave:string, etiqueta:string, clase:string, valor:string, ayuda:?string}> */
    public function campos(): array
    {
        return $this->campos;
    }

    /** @return array<int,array{clave:string, texto:string, url:string, activa:bool, cargada:bool}> */
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

    /** La pastilla: «Cargada» si esta endoscopía ya tiene datos, «Sin cargar» si no. */
    public function estado(): array
    {
        return $this->cargada
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargada',
                'titulo' => 'Esta endoscopía ya tiene datos guardados']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no se cargó esta endoscopía'];
    }

    public function ayuda(): string
    {
        return 'Cada endoscopía se guarda en la consulta y se vuelve a mostrar cuando la abres de nuevo. El «Texto para '
            . 'imprimir» es lo que sale en el informe.';
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay datos escritos sin guardar en los procedimientos. Si sales ahora, se pierden.';
    }
}
