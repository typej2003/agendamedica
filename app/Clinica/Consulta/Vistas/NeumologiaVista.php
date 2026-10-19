<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\NeumologiaDeLaConsulta;

/**
 * **La solapa Neumología de la evaluación física** (neumología pediátrica, 2026-10-19): los tres informes del
 * escritorio —R.X. de tórax, espirometría y broncoscopía— en un solo formulario.
 *
 * Se guarda con un **botón explícito** (es texto clínico, como la evolución y la enfermedad actual) y la pantalla
 * avisa cuando hay algo escrito sin guardar. El ViewModel no sabe de `Request` ni de base de datos.
 */
final class NeumologiaVista
{
    /**
     * @param  array<string,?string>  $textos  los tres textos guardados, por clave de solapa
     * @param  string  $urlGuardar  a dónde postea "Guardar"
     * @param  string  $urlVolver  la vuelta a la solapa
     * @param  array<string,mixed>  $old  lo escrito en un POST que falló (no se borra, guía de UI §3)
     */
    public function __construct(
        private array $textos,
        private string $urlGuardar,
        private string $urlVolver = '',
        private array $old = [],
    ) {
    }

    /**
     * Las tres solapas con su valor para el formulario: `[['clave','etiqueta','filas','ayuda','valor'], …]`.
     *
     * @return array<int,array{clave:string, etiqueta:string, filas:int, ayuda:string, valor:string}>
     */
    public function campos(): array
    {
        $salida = [];

        foreach (NeumologiaDeLaConsulta::campos() as $clave => $campo) {
            $salida[] = [
                'clave'    => $clave,
                'etiqueta' => $campo['etiqueta'],
                'filas'    => $campo['filas'],
                'ayuda'    => $campo['ayuda'],
                'valor'    => array_key_exists($clave, $this->old)
                    ? (string) $this->old[$clave]
                    : (string) ($this->textos[$clave] ?? ''),
            ];
        }

        return $salida;
    }

    /** El estado de la solapa como pastilla: "Cargado" si hay algún informe y "Sin cargar" si no. */
    public function estado(): array
    {
        $conTexto = array_filter($this->textos, fn ($texto) => $texto !== null && $texto !== '');

        return $conTexto !== []
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargado',
                'titulo' => 'La consulta ya tiene informes de neumología guardados']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay informes de neumología en esta consulta'];
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** El aviso de cambios sin guardar (lo usa `consulta.js` por `data-aviso`). */
    public function avisoSinGuardar(): string
    {
        return 'Hay informes de neumología escritos sin guardar. Si sales ahora, se pierden.';
    }
}
