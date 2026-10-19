<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **Indicadores incidentes** (nefrología pediátrica, 2026-10-19): el panel de números que compone el resumen de
 * laboratorio y lo deja en `consultas.resultadoexamencomp`.
 *
 * La pantalla tiene dos partes, como el escritorio:
 *
 *  - **los campos** (26, en dos grupos: sangre y orina) que el médico teclea en cada consulta. Son
 *    **transitorios**: el escritorio no los guarda y la web tampoco (ver `IndicadoresIncidentes`), así que
 *    abren vacíos en cada atención y lo que queda es el texto;
 *  - **el resumen guardado**, de sólo lectura, que es lo que imprimen el resumen médico y la evaluación
 *    pre-operatoria. Si no hay, la pantalla lo dice.
 *
 * El ViewModel no sabe de `Request` ni de base de datos: recibe los grupos y el texto ya leídos.
 */
final class IndicadoresIncidentesVista
{
    /**
     * @param  array<string,array<string,string>>  $grupos  grupo => [clave => etiqueta], en el orden del escritorio
     * @param  string|null  $guardado  el texto que hoy tiene `consultas.resultadoexamencomp`
     * @param  string  $urlGuardar  a dónde postea "Guardar"
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de la guía de UI)
     * @param  array<string,mixed>  $old  lo escrito en un POST que falló la validación (no se borra, guía de UI §3)
     */
    public function __construct(
        private array $grupos,
        private ?string $guardado,
        private string $urlGuardar,
        private string $urlVolver = '',
        private array $old = [],
    ) {
    }

    /**
     * Los grupos del formulario, ya con el valor que se está mostrando: `[['titulo' => 'Sangre', 'campos' =>
     * [['clave', 'etiqueta', 'valor'], …]], …]`.
     *
     * @return array<int,array{titulo:string, campos:array<int,array{clave:string, etiqueta:string, valor:string}>}>
     */
    public function grupos(): array
    {
        $salida = [];

        foreach ($this->grupos as $titulo => $campos) {
            $filas = [];

            foreach ($campos as $clave => $etiqueta) {
                $filas[] = [
                    'clave'    => $clave,
                    'etiqueta' => $etiqueta,
                    'valor'    => trim((string) ($this->old[$clave] ?? '')),
                ];
            }

            $salida[] = ['titulo' => $titulo, 'campos' => $filas];
        }

        return $salida;
    }

    /** El resumen guardado, o `null` si la consulta todavía no tiene ninguno. */
    public function guardado(): ?string
    {
        return $this->guardado;
    }

    public function tieneGuardado(): bool
    {
        return $this->guardado !== null && $this->guardado !== '';
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** El estado del resumen como pastilla: "Cargado" cuando ya hay uno guardado y "Sin cargar" cuando no. */
    public function estado(): array
    {
        return $this->tieneGuardado()
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargado',
                'titulo' => 'La consulta ya tiene un resumen de laboratorio guardado']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay un resumen de laboratorio en esta consulta'];
    }

    /** La ayuda de la pantalla: qué compone el resumen y qué no se guarda. */
    public function ayuda(): string
    {
        return 'Escribe los valores de laboratorio y el resumen se compone solo, con el mismo orden y las mismas '
            . 'etiquetas que el escritorio. Al guardar queda en el resultado de exámenes de la consulta, que es lo '
            . 'que imprimen el resumen médico y la evaluación pre-operatoria. Los números no se guardan: si vuelves '
            . 'a la consulta, el resumen queda como texto y los campos abren vacíos.';
    }

    /** El aviso de cambios sin guardar (lo usa `consulta.js` por `data-aviso`). */
    public function avisoSinGuardar(): string
    {
        return 'Hay valores escritos sin guardar en los indicadores. Si sales ahora, se pierden.';
    }

    /** El marcador de cada campo: es un número, no texto libre. */
    public function marcador(): string
    {
        return '—';
    }
}
